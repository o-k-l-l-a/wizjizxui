<?php

include_once '../baseInfo.php';
include_once '../config.php';

/* ---------------- LOCK SYSTEM ---------------- */

$lockFile = __DIR__ . "/broadcast.lock";

if(file_exists($lockFile)){
    exit; // جلوگیری از اجرای همزمان
}

file_put_contents($lockFile, time());

/* ---------------- CONFIG ---------------- */

$batchSize = 30;          // کنترل سرعت
$sleepMicro = 300000;     // 0.3 sec delay

/* ---------------- GET TASK ---------------- */

$stmt = $connection->prepare("SELECT * FROM send_list WHERE state=1 LIMIT 1");
$stmt->execute();
$task = $stmt->get_result()->fetch_assoc();
$stmt->close();

if(!$task){
    unlink($lockFile);
    exit;
}

$taskId   = $task['id'];
$offset   = (int)$task['offset'];
$type     = $task['type'];
$text     = $task['text'];
$file_id  = $task['file_id'];
$chat_id  = $task['chat_id'];
$msg_id   = $task['message_id'];

$sent = 0;
$failed = 0;

/* ---------------- LOAD USERS ---------------- */

$stmt = $connection->prepare("
    SELECT userid FROM users
    ORDER BY id ASC
    LIMIT ? OFFSET ?
");

$stmt->bind_param("ii", $batchSize, $offset);
$stmt->execute();
$res = $stmt->get_result();
$stmt->close();

/* ---------------- DONE ---------------- */

if($res->num_rows == 0){

    sendMessage(
        "✅ Broadcast کامل شد\n📊 Sent: {$task['sent']}\n❌ Failed: {$task['failed']}",
        null,
        null,
        $admin
    );

    $stmt = $connection->prepare("DELETE FROM send_list WHERE id=?");
    $stmt->bind_param("i", $taskId);
    $stmt->execute();
    $stmt->close();

    unlink($lockFile);
    exit;
}

/* ---------------- KEYBOARD ---------------- */

$keys = json_encode([
    'inline_keyboard' => [
        [
            ['text' => $buttonValues['start_bot'] ?? 'Start', 'callback_data' => "mainMenu"]
        ]
    ]
]);

/* ---------------- SEND LOOP ---------------- */

while($u = $res->fetch_assoc()){

    $uid = $u['userid'];

    $ok = false;

    try {

        switch($type){

            case 'text':
                $ok = sendMessage($text, $keys, null, $uid);
                break;

            case 'photo':
                $ok = bot('sendPhoto', [
                    'chat_id'=>$uid,
                    'photo'=>$file_id,
                    'caption'=>$text,
                    'reply_markup'=>$keys
                ]);
                break;

            case 'video':
                $ok = bot('sendVideo', [
                    'chat_id'=>$uid,
                    'video'=>$file_id,
                    'caption'=>$text,
                    'reply_markup'=>$keys
                ]);
                break;

            case 'audio':
                $ok = bot('sendAudio', [
                    'chat_id'=>$uid,
                    'audio'=>$file_id,
                    'caption'=>$text,
                    'reply_markup'=>$keys
                ]);
                break;

            case 'document':
                $ok = bot('sendDocument', [
                    'chat_id'=>$uid,
                    'document'=>$file_id,
                    'caption'=>$text,
                    'reply_markup'=>$keys
                ]);
                break;

            case 'forwardall':
                $ok = forwardmessage($uid, $chat_id, $msg_id);
                break;
        }

        if($ok){
            $sent++;
        } else {
            $failed++;
        }

    } catch(Exception $e){
        $failed++;
    }

    usleep($sleepMicro); // مهم برای جلوگیری از flood
}

/* ---------------- UPDATE PROGRESS ---------------- */

$stmt = $connection->prepare("
    UPDATE send_list
    SET offset = offset + ?,
        sent = sent + ?,
        failed = failed + ?
    WHERE id = ?
");

$stmt->bind_param("iiii", $batchSize, $sent, $failed, $taskId);
$stmt->execute();
$stmt->close();

/* ---------------- RELEASE LOCK ---------------- */

unlink($lockFile);

?>