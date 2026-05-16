<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include '../baseInfo.php';
include '../config.php';

//==================== INPUT ==============================

if(empty($_GET['hash_id'])){
    showForm("درخواست نامعتبر است");
    exit;
}

$hash_id = $_GET['hash_id'];

if(!isset($_GET['zarinpal']) && !isset($_GET['nowpayment']) && !isset($_GET['nextpay'])){
    showForm("درگاه انتخاب نشده");
    exit;
}

//==================== GET PAYMENT ==============================

$stmt = $connection->prepare("SELECT * FROM pays WHERE hash_id=? AND state='pending'");
$stmt->bind_param("s", $hash_id);
$stmt->execute();
$res = $stmt->get_result();
$stmt->close();

if($res->num_rows == 0){
    showForm("پرداخت یافت نشد");
    exit;
}

$pay = $res->fetch_assoc();

//==================== SAFE PLAN CHECK ==============================

$plan_id = intval($pay['plan_id']);

// 🔥 مهم‌ترین بخش: جلوگیری کامل از plan_id = 0
if($plan_id <= 0){

    // لاگ برای اینکه بفهمی مشکل از کجاست
    error_log("INVALID PLAN_ID | hash_id: $hash_id | plan_id: ".$pay['plan_id']);

    showForm("پرداخت نامعتبر است، لطفاً دوباره تلاش کنید");
    exit;
}

//==================== GET PLAN ==============================

$stmt = $connection->prepare("SELECT * FROM server_plans WHERE id=?");
$stmt->bind_param("i", $plan_id);
$stmt->execute();
$planRes = $stmt->get_result();
$stmt->close();

// fallback با inbound_id
if($planRes->num_rows == 0){
    $stmt = $connection->prepare("SELECT * FROM server_plans WHERE inbound_id=?");
    $stmt->bind_param("i", $plan_id);
    $stmt->execute();
    $planRes = $stmt->get_result();
    $stmt->close();
}

// اگر هیچ پلنی نبود
if($planRes->num_rows == 0){
    error_log("PLAN NOT FOUND | plan_id: $plan_id | hash_id: $hash_id");

    showForm("پرداخت در حال بررسی است، بعداً تلاش کنید");
    exit;
}

$plan = $planRes->fetch_assoc();

//==================== DATA ==============================

$amount = $pay['price'];
$type = $pay['type'];
$orderId = $pay['id'];

//==================== DESCRIPTION ==============================

$desc = "پرداخت";

if($type == "BUY_SUB") $desc = "خرید اکانت";
elseif($type == "RENEW_ACCOUNT") $desc = "تمدید اکانت";
elseif($type == "INCREASE_WALLET") $desc = "شارژ کیف پول";

//==================== PAYMENT KEYS ==============================

$stmt = $connection->prepare("SELECT value FROM setting WHERE type='PAYMENT_KEYS'");
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$keys = $row ? json_decode($row['value'], true) : [];

//==================== NOWPAYMENT ==============================

if(isset($_GET['nowpayment'])){

     $url = rtrim($botUrl, '/') . '/api/Tether-Price.php';
     $dollarPrice = (int) file_get_contents($url);

    if($dollarPrice <= 0){
        showForm("خطا در دریافت قیمت تتر");
        exit;
    }

    $usd = round($amount / $dollarPrice, 2);

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => "https://api.nowpayments.io/v1/invoice",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "X-API-KEY: " . ($keys['nowpayment'] ?? ''),
            "Content-Type: application/json"
        ],
        CURLOPT_POSTFIELDS => json_encode([
            "price_amount" => $usd,
            "price_currency" => "usd",
            "order_id" => $hash_id,
            "order_description" => $desc,
            "success_url" => $botUrl . "pay/back.php?nowpayment&hash_id=$hash_id"
        ])
    ]);

    $response = curl_exec($ch);

    if(curl_errno($ch)){
        showForm("خطای اتصال به درگاه");
        exit;
    }

    curl_close($ch);

    $res = json_decode($response);

    if(!isset($res->invoice_url)){
        showForm("خطا در ساخت پرداخت");
        exit;
    }

    $stmt = $connection->prepare("UPDATE pays SET payid=? WHERE hash_id=?");
    $stmt->bind_param("is", $res->id, $hash_id);
    $stmt->execute();
    $stmt->close();

    header("Location: ".$res->invoice_url);
    exit;
}
//==================== ZARINPAL ==============================

if(isset($_GET['zarinpal'])){

    try{

        $client = new SoapClient("https://www.zarinpal.com/pg/services/WebGate/wsdl");

        $result = $client->PaymentRequest([
            "MerchantID" => $keys['zarinpal'],
            "Amount" => $amount,
            "Description" => $desc,
            "CallbackURL" => $botUrl . "pay/back.php?zarinpal&hash_id=$hash_id"
        ]);

        header("Location: https://www.zarinpal.com/pg/StartPay/".$result->Authority);
        exit;

    }catch(Exception $e){
        showForm("خطا در زرین‌پال");
        exit;
    }
}

//==================== NEXTPAY ==============================

if(isset($_GET['nextpay'])){

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => "https://nextpay.org/nx/gateway/token",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            "api_key" => $keys['nextpay'],
            "amount" => $amount,
            "order_id" => $orderId,
            "callback_uri" => $botUrl . "pay/back.php?nextpay&hash_id=$hash_id"
        ])
    ]);

    $res = json_decode(curl_exec($ch));
    curl_close($ch);

    if(isset($res->code) && $res->code == -1){

        $stmt = $connection->prepare("UPDATE pays SET payid=? WHERE hash_id=?");
        $stmt->bind_param("ss", $res->trans_id, $hash_id);
        $stmt->execute();
        $stmt->close();

        header("Location: https://nextpay.org/nx/gateway/payment/".$res->trans_id);
        exit;
    }

    showForm("خطا در پرداخت");
    exit;
}

//==================== ERROR ==============================

showForm("درگاه نامعتبر");

//==================== VIEW ==============================

function showForm($msg){
?>
<html dir="rtl">
<head>
<meta charset="utf-8">
<title><?php echo $msg; ?></title>
</head>
<body style="text-align:center;font-family:tahoma;padding-top:50px">
<h2><?php echo $msg; ?></h2>
</body>
</html>
<?php
}
?>
