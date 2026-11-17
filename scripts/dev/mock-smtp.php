<?php
// Имитация SMTP-сервера для локального стенда: принимает письма на 127.0.0.1:2525 и печатает тему и текст
// в вывод контейнера строками MAIL. Запуск: php mock-smtp.php (scripts/dev/run.sh делает это сам).
$server = stream_socket_server('tcp://127.0.0.1:2525', $errno, $errstr);
if (!$server) { fwrite(STDERR, "mock-smtp: $errstr\n"); exit(1); }
$out = fopen('php://stderr', 'w');
while ($c = @stream_socket_accept($server, -1)) {
  $say = function ($line) use ($c) { fwrite($c, $line . "\r\n"); };
  $say('220 mock-smtp ready');
  $data = null;
  $step = 0;
  while (($line = fgets($c)) !== false) {
    if ($data !== null) {
      if (rtrim($line, "\r\n") === '.') {
        [$head, $body] = array_pad(explode("\r\n\r\n", $data, 2), 2, '');
        preg_match('/^Subject: =\?UTF-8\?B\?(.+?)\?=/mi', $head, $m);
        preg_match('/^To: (.+)$/mi', $head, $t);
        $text = str_replace("\n", ' | ', trim(base64_decode(str_replace("\r\n", '', $body))));
        fwrite($out, 'MAIL to=' . trim($t[1] ?? '') . ' subject="' . base64_decode($m[1] ?? '') . '" ' . $text . "\n");
        $data = null;
        $say('250 queued');
      } else {
        $data .= $line;
      }
      continue;
    }
    $cmd = strtoupper(substr(trim($line), 0, 4));
    if ($cmd === 'EHLO' || $cmd === 'HELO') { fwrite($c, "250-mock-smtp\r\n250 AUTH LOGIN\r\n"); }
    elseif ($cmd === 'AUTH') $say('334 VXNlcm5hbWU6');
    elseif ($cmd === 'MAIL' || $cmd === 'RCPT') $say('250 ok');
    elseif ($cmd === 'DATA') { $data = ''; $say('354 go'); }
    elseif ($cmd === 'QUIT') { $say('221 bye'); break; }
    elseif (strpos($line, ':') === false && trim($line) !== '') {
      // ответы на AUTH LOGIN: имя → снова 334, пароль → 235
      $say(++$step % 2 ? '334 UGFzc3dvcmQ6' : '235 ok');
    } else $say('500 ?');
  }
  fclose($c);
}
