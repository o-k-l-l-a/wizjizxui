<?php

include "../baseInfo.php";
include "../config.php";

$connection = new mysqli(
    'localhost',
    $dbUserName,
    $dbPassword,
    $dbName
);

if($connection->connect_error){
    exit("Database Error");
}

$connection->set_charset("utf8mb4");

if(!isset($_GET['token'])){
    exit("Wrong token");
}

$token = $_GET['token'];

if(!preg_match('/^[a-zA-Z0-9]{30}$/',$token)){
    exit("Wrong token");
}

$stmt = $connection->prepare(
"SELECT * FROM `orders_list` WHERE `token` = ?"
);

$stmt->bind_param("s", $token);

$stmt->execute();

$info = $stmt->get_result()->fetch_assoc();

$stmt->close();

if(!$info){
    exit("Wrong token");
}

$remark = $info['remark'];
$uuid = $info['uuid'] ?? "0";
$server_id = $info['server_id'];
$inbound_id = $info['inbound_id'];
$protocol = $info['protocol'];
$rahgozar = $info['rahgozar'];
$file_id = $info['fileid'];

$stmt = $connection->prepare(
"SELECT * FROM `server_plans` WHERE `id`=?"
);

$stmt->bind_param("i", $file_id);

$stmt->execute();

$file_detail = $stmt->get_result()->fetch_assoc();

$customPath = $file_detail['custom_path'];
$customPort = $file_detail['custom_port'];
$customSni = $file_detail['custom_sni'];

$stmt->close();

$response = getJson($server_id)->obj;

$total = 0;
$up = 0;
$down = 0;
$port = 0;
$netType = "ws";

if($inbound_id == 0){

    foreach($response as $row){

        $clientInbound = $row->id;

        $clients = json_decode($row->settings)->clients;

        if(
            $clients[0]->id == $uuid ||
            $clients[0]->password == $uuid
        ){

            $total = $row->total;
            $port = $row->port;
            $up = $row->up;
            $down = $row->down;

            $netType =
            json_decode($row->streamSettings)->network;

            break;
        }
    }

}else{

    foreach($response as $row){

        if($row->id == $inbound_id){

            $clientInbound = $row->id;

            $port = $row->port;

            $clientsStates = $row->clientStats;

            $clients =
            json_decode($row->settings)->clients;

            foreach($clients as $client){

                if(
                    $client->id == $uuid ||
                    $client->password == $uuid
                ){

                    $email = $client->email;

                    $emails =
                    array_column($clientsStates,'email');

                    $emailKey =
                    array_search($email,$emails);

                    $total =
                    $clientsStates[$emailKey]->total;

                    $up =
                    $clientsStates[$emailKey]->up;

                    $down =
                    $clientsStates[$emailKey]->down;

                    break;
                }
            }
        }
    }
}

$totalUsedRaw =
round(($up + $down) / 1073741824, 2);

$totalRaw =
round($total / 1073741824, 2);

$totalUsed =
$totalUsedRaw . " GB";

$totalText =
$totalRaw . " GB";

$daysLeft =
round(
($info['expire_date'] - time()) / 86400,
1
);

$link = json_decode($info['link'])[0];

if(preg_match('/vmess/', $link)){

    $link_info = json_decode(
        base64_decode(
            str_replace('vmess://','',$link)
        )
    );

    $uniqid = $link_info->id;

}else{

    $link_info = parse_url($link);

    $uniqid = $link_info['user'];
}

$newRemark =
preg_replace(
"/\(📊.+-.+\|📆.+\)/",
"",
$remark
)
.
"(📊".$totalUsed." - ".$totalText."|📆".$daysLeft.")";

if($inbound_id == 0){

    $res = editInboundRemark(
        $server_id,
        $uuid,
        $newRemark
    );

}else{

    $res = editClientRemark(
        $server_id,
        $clientInbound,
        $uuid,
        $newRemark
    );
}

if(!$res->success){
    exit("Error occured");
}

$vraylink = getConnectionLink(
    $server_id,
    $uniqid,
    $protocol,
    $newRemark,
    $port,
    $netType,
    $inbound_id,
    $rahgozar,
    $customPath,
    $customPort,
    $customSni
);

$stmt = $connection->prepare(
"UPDATE `orders_list`
SET `link` = ?, `remark` = ?
WHERE `token` = ?"
);

$newLink = json_encode($vraylink);

$stmt->bind_param(
    "sss",
    $newLink,
    $newRemark,
    $token
);

$stmt->execute();

$stmt->close();

$subLink = implode("\n", $vraylink);

$percent = 0;

if($totalRaw > 0){

    $percent =
    round(($totalUsedRaw / $totalRaw) * 100);

    if($percent > 100){
        $percent = 100;
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
name="viewport"
content="width=device-width, initial-scale=1.0"
/>

<title>VPN Dashboard</title>

<style>

@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700;800&display=swap');

*{
    margin:0;
    padding:0;
    box-sizing:border-box;
}

body{

    font-family:'Inter',sans-serif;

    min-height:100vh;

    background:
    radial-gradient(circle at top left,#1e3a8a 0%,transparent 35%),
    radial-gradient(circle at bottom right,#7c3aed 0%,transparent 35%),
    linear-gradient(135deg,#020617,#0f172a);

    overflow:hidden;

    display:flex;
    justify-content:center;
    align-items:center;

    padding:20px;

    color:white;
}

.bg1,
.bg2{

    position:absolute;

    width:320px;
    height:320px;

    border-radius:50%;

    filter:blur(120px);

    z-index:0;
}

.bg1{

    background:#2563eb;

    top:-120px;
    left:-120px;
}

.bg2{

    background:#9333ea;

    bottom:-120px;
    right:-120px;
}

.card{

    position:relative;

    z-index:2;

    width:100%;
    max-width:430px;

    padding:28px;

    border-radius:34px;

    background:
    rgba(15,23,42,.72);

    backdrop-filter:blur(18px);

    border:
    1px solid rgba(255,255,255,.08);

    box-shadow:
    0 0 40px rgba(0,0,0,.45),
    inset 0 1px 0 rgba(255,255,255,.05);

    overflow:hidden;
}

.card::before{

    content:"";

    position:absolute;

    inset:0;

    background:
    linear-gradient(
    135deg,
    rgba(255,255,255,.05),
    transparent 35%
    );

    pointer-events:none;
}

.top{

    display:flex;
    justify-content:space-between;
    align-items:center;

    margin-bottom:28px;
}

.logo{

    display:flex;
    align-items:center;
    gap:14px;
}

.logo-icon{

    width:58px;
    height:58px;

    border-radius:20px;

    background:
    linear-gradient(
    135deg,
    #3b82f6,
    #8b5cf6
    );

    display:flex;
    justify-content:center;
    align-items:center;

    font-size:24px;

    box-shadow:
    0 10px 25px rgba(59,130,246,.35);
}

.logo-text h2{

    font-size:22px;

    font-weight:800;
}

.logo-text p{

    font-size:13px;

    opacity:.7;
}

.status{

    padding:8px 14px;

    border-radius:999px;

    background:
    rgba(34,197,94,.15);

    border:
    1px solid rgba(34,197,94,.25);

    color:#4ade80;

    font-size:13px;

    font-weight:700;
}

.usage-card{

    background:
    linear-gradient(
    145deg,
    rgba(255,255,255,.05),
    rgba(255,255,255,.02)
    );

    border-radius:24px;

    padding:22px;

    margin-bottom:22px;

    border:
    1px solid rgba(255,255,255,.06);
}

.label{

    font-size:13px;

    opacity:.7;

    margin-bottom:10px;
}

.big{

    font-size:34px;

    font-weight:800;

    line-height:1;
}

.big span{

    font-size:16px;

    opacity:.7;

    font-weight:500;
}

.progress{

    margin-top:20px;

    width:100%;
    height:18px;

    background:
    rgba(255,255,255,.06);

    border-radius:999px;

    overflow:hidden;

    position:relative;
}

.bar{

    height:100%;

    width:<?= $percent ?>%;

    border-radius:999px;

    background:
    linear-gradient(
    90deg,
    #06b6d4,
    #3b82f6,
    #8b5cf6
    );

    box-shadow:
    0 0 18px rgba(59,130,246,.7);

    position:relative;
}

.bar::after{

    content:"";

    position:absolute;

    top:0;
    right:0;

    width:40px;
    height:100%;

    background:
    linear-gradient(
    90deg,
    transparent,
    rgba(255,255,255,.6)
    );

    animation:shine 2s linear infinite;
}

@keyframes shine{

    from{
        transform:translateX(-40px);
    }

    to{
        transform:translateX(250px);
    }
}

.stats{

    display:grid;

    grid-template-columns:1fr 1fr;

    gap:16px;

    margin-bottom:22px;
}

.stat{

    padding:18px;

    border-radius:22px;

    background:
    rgba(255,255,255,.04);

    border:
    1px solid rgba(255,255,255,.06);
}

.stat-title{

    font-size:13px;

    opacity:.7;

    margin-bottom:10px;
}

.stat-value{

    font-size:24px;

    font-weight:800;
}

.subbox{

    background:#020617;

    border-radius:20px;

    padding:16px;

    font-size:11px;

    line-height:1.7;

    max-height:140px;

    overflow:auto;

    word-break:break-all;

    border:
    1px solid rgba(255,255,255,.06);

    opacity:.85;
}

.buttons{

    display:flex;

    gap:14px;

    margin-top:20px;
}

button{

    flex:1;

    border:none;

    padding:15px;

    border-radius:18px;

    font-size:15px;

    font-weight:700;

    cursor:pointer;

    transition:.25s;
}

button:hover{

    transform:translateY(-3px);
}

.btn1{

    background:
    linear-gradient(
    135deg,
    #2563eb,
    #4f46e5
    );

    color:white;

    box-shadow:
    0 10px 25px rgba(37,99,235,.35);
}

.btn2{

    background:
    linear-gradient(
    135deg,
    #059669,
    #10b981
    );

    color:white;

    box-shadow:
    0 10px 25px rgba(16,185,129,.3);
}

::-webkit-scrollbar{
    width:6px;
}

::-webkit-scrollbar-thumb{

    background:#334155;

    border-radius:20px;
}

</style>

</head>

<body>

<div class="bg1"></div>
<div class="bg2"></div>

<div class="card">

<div class="top">

<div class="logo">

<div class="logo-icon">
⚡
</div>

<div class="logo-text">
<h2>VPN Dashboard</h2>
<p>Subscription Manager</p>
</div>

</div>

<div class="status">
ACTIVE
</div>

</div>

<div class="usage-card">

<div class="label">
Traffic Usage
</div>

<div class="big">
<?= $totalUsedRaw ?>
<span>/ <?= $totalRaw ?> GB</span>
</div>

<div class="progress">
<div class="bar"></div>
</div>

</div>

<div class="stats">

<div class="stat">

<div class="stat-title">
Remaining Days
</div>

<div class="stat-value">
<?= $daysLeft ?>
</div>

</div>

<div class="stat">

<div class="stat-title">
Protocol
</div>

<div class="stat-value">
<?= strtoupper($protocol) ?>
</div>

</div>

</div>

<div
class="subbox"
id="sub"
><?= htmlspecialchars($subLink) ?></div>

<div class="buttons">

<button
class="btn1"
onclick="copySub()"
>
📋 Copy Sub
</button>

<button
class="btn2"
onclick="copyConfig()"
>
⚡ Copy Config
</button>

</div>

</div>

<script>

function notify(text){

    const div =
    document.createElement("div");

    div.innerText = text;

    div.style.position = "fixed";
    div.style.bottom = "20px";
    div.style.left = "50%";
    div.style.transform = "translateX(-50%)";
    div.style.background = "#111827";
    div.style.padding = "14px 20px";
    div.style.borderRadius = "14px";
    div.style.color = "white";
    div.style.zIndex = "9999";
    div.style.boxShadow =
    "0 10px 25px rgba(0,0,0,.35)";

    document.body.appendChild(div);

    setTimeout(()=>{
        div.remove();
    },2000);
}

function copySub(){

    navigator.clipboard.writeText(
        window.location.href
    );

    notify("Subscription copied");
}

function copyConfig(){

    navigator.clipboard.writeText(
        document.getElementById("sub").innerText
    );

    notify("Config copied");
}

</script>

</body>
</html>