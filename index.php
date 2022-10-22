<?php
// Only report errors, do not report warnings
error_reporting(E_ERROR);

// Disable script timeout - timeouts can be handled by the AJAX calling thread instead
ini_set('max_execution_time', 0);

// // Include library for detecting browser
// require_once('url_suffixes.php');

$SERVER_URL = getServerUrl();

$queryString = $_SERVER['QUERY_STRING'];
if (strlen($queryString) > 0) {
  if (strStartsWith($queryString, 'pageUrl=')) {
    $ampersandPos = strpos($queryString, '&');
    if ($ampersandPos === false) {
      $pageUrl = urldecode(substr($queryString, 8));
    } else {
      $pageUrl = urldecode(substr($queryString, 8, $ampersandPos - 8))
        . substr($queryString, $ampersandPos);
    }
  } else {
    $pageUrl = urldecode($queryString);
  }
  $pageUrl = preprocessAbsoluteUrl($pageUrl);
  sendResponseForHttpRequest($pageUrl, 'GET');
} else {
  // Redirect to home page
  header("Location: index.html");
}

// Send an HTTP request (see https://stackoverflow.com/questions/5647461) and respond according to the response 
function sendResponseForHttpRequest($pageUrl) {
  $curlResponse = sendHttpRequest($pageUrl);

  $headers = $curlResponse['headers'];
  $content = $curlResponse['content'];
  sendHeaders($headers);

  $contentType = getContentType($headers);
  $isTextual = preg_match('/(?:text\/|html|json|xml|multipart)/i', $contentType);
  if ($isTextual) {
    $content = convertLinksInContent($content, $pageUrl);
  }
  echo ($content);
}

function sendHttpRequest($url) {
  global $SERVER_URL;

  //TODO: Random user agent
  // $useragent = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_8_2) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/44.0.2403.89 Safari/537.36';
  $curDir = __DIR__;
  $urlFromHostToDomain = getUrlFromHostToDomain($url);
  $cookieFilenameBase = urlencode("{$urlFromHostToDomain}-{$_SERVER['REMOTE_ADDR']}-{$_SERVER['HTTP_X_FORWARDED_FOR']}-{$_SERVER['HTTP_USER_AGENT']}");
  $cookieFilename = "{$curDir}/cookies/{$cookieFilenameBase}.txt";

  $ch = curl_init($url);
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $headers = getallheaders();
    $headers['Referer'] = preprocessAbsoluteUrl(str_replace("{$SERVER_URL}?", '', $headers['Referer']));
    $headers['Host'] = $headers['Referer'];
    $headers['Origin'] = $headers['Referer'];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, true);
    $payload = file_get_contents('php://input');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
  }
  curl_setopt($ch, CURLOPT_HEADER, false);
  curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFilename);
  curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFilename);
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
  curl_setopt($ch, CURLOPT_ENCODING, "");
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_AUTOREFERER, true);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 60);
  curl_setopt($ch, CURLOPT_TIMEOUT, 120);
  curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
  // curl_setopt($ch, CURLOPT_USERAGENT, $useragent);
  // curl_setopt($ch, CURLOPT_REFERER, 'http://www.google.com/');
  curl_setopt($ch, CURLOPT_VERBOSE, true);
  curl_setopt($ch, CURLOPT_STDERR, fopen("{$curDir}/curl.log", 'w+'));

  //These next lines are for the magic "good cert confirmation"
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
  curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

  //for local domains:
  //you need to get the pem cert file for the root ca or intermediate CA that you signed all the domain certificates with so that PHP curl can use it...sorry batteries not included
  //place the pem or crt ca certificate file in the same directory as the php file for this code to work
  curl_setopt($ch, CURLOPT_CAINFO, "{$curDir}/cacert.pem");
  curl_setopt($ch, CURLOPT_CAPATH, "{$curDir}/cacert.pem");

  $headers = [];

  // this function is called by curl for each header received
  curl_setopt(
    $ch,
    CURLOPT_HEADERFUNCTION,
    function ($curl, $header) use (&$headers) {
      array_push($headers, $header);
      return strlen($header);
    }
  );

  $content = curl_exec($ch);

  if (curl_errno($ch)) {
    echo ("Failed to read from $url");
    $curlError = curl_error($ch);
    echo ("<br/><br/>{$curlError}");
    curl_close($ch);
    exit();
  } else {
    curl_close($ch);
    return array('content' => $content, 'headers' => $headers);
  }
}

function sendHeaders($headers) {
  $skipping = false;
  foreach ($headers as $header) {
    $lowerCaseHeader = strtolower($header);
    if (strStartsWith($lowerCaseHeader, 'http/1.1 3')) {
      $skipping = true;
    } else if (strStartsWith($lowerCaseHeader, 'http/')) {
      $skipping = false;
    }
    if (
      !$skipping
      && !strStartsWith($lowerCaseHeader, 'content-encoding')
      && !strStartsWith($lowerCaseHeader, 'transfer-encoding')
    ) {
      $header = convertLinksInString($header);
      header($header);
    }
  }
}

function getContentType($httpResponseHeaderArray) {
  $httpResponseHeaderStr = implode("\n", $httpResponseHeaderArray);
  if (preg_match_all('/^content-type\s*:\s*(.*)$/mi', $httpResponseHeaderStr, $matches, PREG_SET_ORDER)) {
    return end($matches)[1];
  } else {
    return NULL;
  }
}

function getCharset($contentType) {
  return preg_match('/;\s*charset\s*=\s*(.*)$/i', $contentType, $matches) ? $matches[1] : '';
}

function convertLinksInContent($content, $pageUrl) {
  global $SERVER_URL;
  $pageHost = getUrlHost($pageUrl);
  $content = str_replace($_SERVER['HTTP_HOST'], "{$SERVER_URL}?$pageHost", $content);
  $content = preg_replace_callback(
    '/((?:href|src|action|url)(?:["\']|&quot;|&apos;)?\s*[=:]\s*)(["\']|&quot;|&apos;)(.*?)\2/i',
    function ($matches) use ($pageUrl) {
      $convertedLink = getConvertedLink($matches[3], $pageUrl);
      $replaced = "{$matches[1]}{$matches[2]}{$convertedLink}{$matches[2]}";
      return $replaced;
    },
    $content
  );
  $content = preg_replace_callback(
    '/(action\s*=\s*)(["\'])(.*?)\2(.*?>)/i',
    function ($matches) use ($pageUrl) {
      global $SERVER_URL;
      if (strStartsWith($matches[3], "{$SERVER_URL}?")) {
        $pageUrlValue = substr($matches[3], strlen($SERVER_URL) + 1);
        $replaced = "{$matches[1]}{$matches[2]}{$SERVER_URL}?{$matches[2]}{$matches[4]}<input name='pageUrl' value='{$pageUrlValue}' type='hidden'/>";
      } else {
        $replaced = $matches[0];
      }
      return $replaced;
    },
    $content);
  return $content;
}

function convertLinksInString(string $str) {
  return preg_replace_callback(
    '/https?:\/\/(?:www\.)?[-a-zA-Z0-9@:%._\+~#=]{2,256}\.[a-z]{2,4}\b(?:[-a-zA-Z0-9@:%_\+.~#?&\/=]*)/i',
    function ($matches) {
      global $SERVER_URL;
      return strStartsWith($matches[0], $SERVER_URL) ? $matches[0] : "{$SERVER_URL}?{$matches[0]}";
    },
    $str
  );
}

function getConvertedLink($linkUrl, $pageUrl) {
  global $SERVER_URL;
  if ($linkUrl === '' || strStartsWith($linkUrl, '#') || strStartsWith($linkUrl, "$SERVER_URL?")) return $linkUrl;
  if (preg_match('/(?:\:\/\/|^javascript:|^about:)/i', $linkUrl)) {
    if (!preg_match('/^(?:https?)?:\\/\\//i', $linkUrl)) return $linkUrl;
  } else {
    if (strStartsWith($linkUrl, '/')) {
      $pageUrlPart = getUrlUpToDomain($pageUrl);
    } else {
      $pageUrlPart = getUrlUpToPath($pageUrl);
    }
    $linkUrl = "{$pageUrlPart}{$linkUrl}";
  }
  $appendChar = !strContains($linkUrl, '?') ? '?' : '';
  $convertedLink = "{$SERVER_URL}?{$linkUrl}{$appendChar}";
  return $convertedLink;
}

function getServerUrl() {
  $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
  return "{$scheme}://{$_SERVER['HTTP_HOST']}{$_SERVER['URL']}";
}

function preprocessAbsoluteUrl($url, $firstRun = true) {
  $parts = parse_url($url);
  $scheme = isset($parts['scheme']) ? $parts['scheme'] : 'http';
  $user = isset($parts['user']) ? $parts['user'] : '';
  $pass = isset($parts['pass']) ? ":{$parts['pass']}" : '';
  $userpass = $user !== '' || $pass !== '' ? "{$user}{$pass}@" : '';
  $host = isset($parts['host'])
    ? (preg_match('/(?:^localhost|^\d{1,3}\.\d{1,3}\d{1,3}\.\d{1,3}|^www\.)/i', $parts['host'])
      ? $parts['host']
      : "www.{$parts['host']}")
    : '';
  $port = isset($parts['port']) ? ":{$parts['port']}" : '';
  $path = isset($parts['path']) ? rtrim($parts['path'], '/') : '';
  $query = isset($parts['query']) ? "?{$parts['query']}" : '';
  $fragment = isset($parts['fragment']) ? "#{$parts['fragment']}" : '';
  $url = "{$scheme}://{$userpass}{$host}{$port}{$path}{$query}{$fragment}";
  if ($firstRun) {
    $url = preprocessAbsoluteUrl($url, false);
  }
  return $url;
}

function getScheme($url) {
  return parse_url($url, PHP_URL_SCHEME);
}

function getHost($url) {
  return parse_url($url, PHP_URL_HOST);
}

function getUrlUpToDomain($url) {
  return getUrlUpToDomainOrPath($url, false);
}

function getUrlFromHostToDomain($url) {
  return getUrlUpToDomainOrPath($url, false, true);
}

function getUrlUpToPath($url) {
  return getUrlUpToDomainOrPath($url, true);
}

function getUrlUpToDomainOrPath($url, $includePath, $fromHost = false) {
  $parts = parse_url($url);
  $scheme = isset($parts['scheme']) && !$fromHost ? "{$parts['scheme']}://" : '';
  $user = isset($parts['user']) ? $parts['user'] : '';
  $pass = isset($parts['pass']) ? ":{$parts['pass']}" : '';
  $userpass = $user !== '' || $pass !== '' && !$fromHost ? "{$user}{$pass}@" : '';
  $host = isset($parts['host']) ? $parts['host'] : '';
  $port = isset($parts['port']) ? ":{$parts['port']}" : '';
  $path = $includePath && isset($parts['path']) ? rtrim($parts['path'], '/') : '';
  return "{$scheme}{$userpass}{$host}{$port}{$path}";
}

function getUrlScheme($url) {
  return parse_url($url, PHP_URL_SCHEME) ?: '';
}

function getUrlHost($url) {
  return parse_url($url, PHP_URL_HOST) ?: '';
}

function strStartsWith(string $haystack, string $needle) {
  return substr($haystack, 0, strlen($needle)) === $needle;
}

function strEndsWith(string $haystack, string $needle) {
  return substr($haystack, strlen($haystack) - strlen($needle)) === $needle;
}

function strContains(string $haystack, string $needle) {
  return strpos($haystack, $needle) !== false;
}
