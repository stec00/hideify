<?php
// Only report errors, do not report warnings
error_reporting(E_ERROR);

// Disable script timeout - timeouts can be handled by the AJAX calling thread instead
ini_set('max_execution_time', 0);

// // Include library for detecting browser
// require_once('url_suffixes.php');

$SERVER_URL = getServerUrl();
$ESC_SERVER_URL = str_replace('/', '\/', preg_quote($SERVER_URL));

$queryString = $_SERVER['QUERY_STRING'];
$payload = file_get_contents('php://input');
if (strlen($queryString) > 0 || $payload > 0) {
  if (strStartsWith($queryString, 'pageUrl=')) {
    $ampersandPos = strpos($queryString, '&');
    if ($ampersandPos === false) {
      $pageUrl = urldecode(substr($queryString, 8));
    } else {
      $pageUrl = urldecode(substr($queryString, 8, $ampersandPos - 8))
        . substr($queryString, $ampersandPos);
    }
  } else if (strStartsWith($payload, 'pageUrl=')) {
    $ampersandPos = strpos($payload, '&');
    if ($ampersandPos === false) {
      $pageUrl = urldecode(substr($payload, 8));
      $payload = '';
    } else {
      $pageUrl = urldecode(substr($payload, 8, $ampersandPos - 8));
      $payload = substr($payload, $ampersandPos + 1);
      $payload = convertUrlsInString($payload, $pageUrl);
    }
  } else {
    $pageUrl = urldecode($queryString);
  }
  $pageUrl = preprocessAbsoluteUrl($pageUrl);
  sendResponseForHttpRequest($pageUrl, $payload);
} else {
  // Redirect to home page
  header("Location: index.html");
}

// Send an HTTP request (see https://stackoverflow.com/questions/5647461) and respond according to the response 
function sendResponseForHttpRequest($pageUrl, $payload) {
  $curlResponse = sendHttpRequest($pageUrl, $payload);

  $content = $curlResponse['content'];
  $headers = $curlResponse['headers'];
  $pageUrl = sendHeaders($headers, $pageUrl);

  $contentType = getContentType($headers);
  $isTextual = preg_match('/(?:text\/|html|json|xml|multipart)/i', $contentType);
  if ($isTextual) {
    $content = convertUrlsInContent($content, $pageUrl);
  }
  echo ($content);
}

function sendHttpRequest($url, $payload) {
  $curDir = __DIR__;
  $cookieFilenameBase = urlencode(
    "{$_SERVER['REMOTE_ADDR']}-{$_SERVER['HTTP_X_FORWARDED_FOR']}-{$_SERVER['HTTP_USER_AGENT']}"
  );
  $cookieFilename = "{$curDir}/cookies/{$cookieFilenameBase}.txt";

  $ch = curl_init($url);
  $requestMethod = $_SERVER['REQUEST_METHOD'];
  if ($requestMethod !== 'GET') {
    $headers = getallheaders();
    $headers['Referer'] = getUrlToPort($url);
    $headers['Host'] = getHost($url);
    $headers['Origin'] = $headers['Referer'];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($requestMethod === 'POST') {
      curl_setopt($ch, CURLOPT_POST, true);
    } else {
      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $requestMethod);
    }
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
  }
  //curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
  //curl_setopt($ch, CURLOPT_HEADER, false); // 0 is default
  curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFilename);
  curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFilename);
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
  curl_setopt($ch, CURLOPT_ENCODING, "");
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_AUTOREFERER, true);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 60);
  curl_setopt($ch, CURLOPT_TIMEOUT, 120);
  curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
  curl_setopt($ch, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']);
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
      $headers[] = $header;
      return strlen($header);
    }
  );

  $content = curl_exec($ch);

  if (curl_errno($ch)) {
    echo ("Failed {$_SERVER['REQUEST_METHOD']} request to {$url}");
    $curlError = curl_error($ch);
    echo ("<br/><br/>{$curlError}");
    curl_close($ch);
    exit();
  } else {
    curl_close($ch);
    return array('content' => $content, 'headers' => $headers);
  }
}

function sendHeaders($headers, $pageUrl) {
  $skipping = false;
  $newHeaders = [];
  foreach ($headers as $header) {
    if (preg_match('/^(location:\s*)(.*)/i', $header, $matches)) {
      $pageUrl = getUrlToPath(getAbsoluteUrl($matches[2], $pageUrl));
      $header = "{$matches[1]}{$pageUrl}";
    } else if (preg_match('/^(link:\s+<)([^>]+)(>.*)/i', $header, $matches)) {
      $url = convertUrl($matches[2], $pageUrl);
      $header = "{$matches[1]}{$url}{$matches[3]}";
    } else if (preg_match('/^http\/[\d.]*? 3/i', $header)) {
      $skipping = true;
    } else if (preg_match('/^http\//i', $header)) {
      $skipping = false;
    }

    if (
      !$skipping
      && !preg_match('/^(?:content-encoding|content-security-policy|cross-origin|referrer-policy|timing-allow-origin|transfer-encoding)/i', $header)
    ) {
      $header = convertUrlsInString($header);
      $newHeaders[] = $header;
    }
  }

  foreach ($newHeaders as $header) {
    header($header);
  }
  header('Access-Control-Allow-Origin: *');
  header('Cross-Origin-Resource-Policy: cross-origin');
  header('Referrer-Policy: unsafe-url');
  header('Timing-Allow-Origin: *');

  return $pageUrl;
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

function convertUrlsInContent($content, $pageUrl) {
  global $SERVER_URL, $ESC_SERVER_URL;
  $pageHost = getUrlHost($pageUrl);
  $content = str_replace($_SERVER['HTTP_HOST'], "{$SERVER_URL}?$pageHost", $content);
  // href/src/srcset/url =/: "..."/'...'/`...`/&quot;...&quot;/&apos;...&apos;
  $content = preg_replace_callback(
    '/([:\s])(href|src|srcset|url)((?:["\'`]|&quot;|&apos;)?\s*[=:]\s*)(["\']|&quot;|&apos;)(.*?)\4/i',
    function ($matches) use ($pageUrl) {
      if ($matches[2] === 'srcset') {
        $convertedValue = preg_replace_callback(
          '/([^\s,]*)(.*?(?:,\s*|$))/',
          function ($matches) use ($pageUrl) {
            $convertedUrl = convertUrl($matches[1], $pageUrl);
            return "{$convertedUrl}{$matches[2]}";
          },
          $matches[5]
        );
      } else {
        $convertedValue = convertUrl($matches[5], $pageUrl, in_array($matches[4], array('&quot', '&apos')));
      }
      $replaced = "{$matches[1]}{$matches[2]}{$matches[3]}{$matches[4]}{$convertedValue}{$matches[4]}";
      return $replaced;
    },
    $content
  );
  // : url ( "..."/'...'/... )
  $content = preg_replace_callback(
    '/(:\s*url\s*\(\s*)(["\']|)(.*?)\2(\s*\))/i',
    function ($matches) use ($pageUrl) {
      $convertedValue = convertUrl($matches[3], $pageUrl);
      $replaced = "{$matches[1]}{$matches[2]}{$convertedValue}{$matches[2]}{$matches[4]}";
      return $replaced;
    },
    $content
  );
  // <form ...action = "..."/'...'...>
  $content = preg_replace_callback(
    '/(<form\s+[^>]*)(action\s*=\s*)(["\'])(.*?)\2(.*?>)/i',
    function ($matches) use ($pageUrl) {
      global $SERVER_URL;
      $absUrl = getAbsoluteUrl($matches[4], $pageUrl);
      $appendChar = !strContains($absUrl, '?') && !strContains($absUrl, '#') ? '?' : '';
      $absUrl = "{$absUrl}{$appendChar}";
      $replaced = "{$matches[1]}{$matches[2]}{$matches[3]}{$SERVER_URL}?{$matches[3]}{$matches[5]}<input type='hidden' name='pageUrl' value='{$absUrl}'/>";
      return $replaced;
    },
    $content
  );

  $content = preg_replace_callback(
    "/([=:]\s*)([\"'`]|&quot;|&apos;)(?!$$ESC_SERVER_URL)(https?:\/\/(?!(?:\\2)).+?)\\2/i",
    function ($matches) use ($pageUrl) {
      $convertedValue = convertUrl($matches[3], $pageUrl, in_array($matches[2], array('&quot', '&apos')));
      $replaced = "{$matches[1]}{$matches[2]}{$convertedValue}{$matches[2]}";
      return $replaced;
    },
    $content
  );
  return $content;
}

function convertUrlsInString(string $str) {
  return preg_replace_callback(
    '/https?:\/\/(?:www\.)?[-a-zA-Z0-9@:%._\+~#=]{2,256}\.[a-z]{2,4}\b(?:[-a-zA-Z0-9@:%_\+.~#?&\/=]*)/i',
    function ($matches) {
      global $SERVER_URL;
      return strStartsWith($matches[0], $SERVER_URL) ? $matches[0] : "{$SERVER_URL}?{$matches[0]}";
    },
    $str
  );
}

function convertUrl($url, $pageUrl, $httpEncode = false) {
  global $SERVER_URL;
  $newUrl = $url;
  if ($httpEncode) {
    $newUrl = html_entity_decode($newUrl);
  }
  $newUrl = getAbsoluteUrl($newUrl, $pageUrl);
  if (
    !preg_match('/(?:^$|^\.$|^#)/i', $url)
    && !strStartsWith($url, "{$SERVER_URL}?")
    && (!preg_match('/^(?:(?:[^\/?:]*\:?)\/\/|javascript:|about:|data:)/i', $url)
      || preg_match('/^(?:https?:)?\/\//i', $url))
  ) {
    $newUrl = urlencode($newUrl);
    $newUrl = preg_replace('/%23/', '#', $newUrl, 1);
    $newUrl = "{$SERVER_URL}?{$newUrl}";
    if ($httpEncode) {
      $newUrl = htmlentities($newUrl);
    }
  }
  return $newUrl;
}

function getAbsoluteUrl($url, $pageUrl) {
  global $SERVER_URL;
  if (preg_match('/(?:^$|^\.$|^#)/i', $url) || strStartsWith($url, "{$SERVER_URL}?")) return $url;
  if (preg_match('/^(?:(?:[^\/?:]*\:?)\/\/|javascript:|about:|data:)/i', $url)) {
    if (!preg_match('/^(?:https?:)?\/\//i', $url)) {
      return $url;
    } else {
      $newUrl = $url;
    }
  } else {
    if (strStartsWith($url, '/')) {
      $pageUrlPart = getUrlToPort($pageUrl);
    } else {
      $pageUrlPart = getUrlToPath($pageUrl);
    }
    $newUrl = "{$pageUrlPart}{$url}";
  }
  return $newUrl;
}

function getServerUrl() {
  $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
  return "{$scheme}://{$_SERVER['HTTP_HOST']}{$_SERVER['URL']}";
}

function preprocessAbsoluteUrl($url) {
  $parts = parse_url($url);
  $scheme = isset($parts['scheme']) ? $parts['scheme'] : 'http';
  $user = isset($parts['user']) ? $parts['user'] : '';
  $pass = isset($parts['pass']) ? ":{$parts['pass']}" : '';
  $userpass = $user !== '' || $pass !== '' ? "{$user}{$pass}@" : '';
  $host = isset($parts['host']) ? $parts['host'] : '';
  $port = isset($parts['port']) ? ":{$parts['port']}" : '';
  $path = isset($parts['path']) ? rtrim($parts['path'], '/') : '';
  $query = isset($parts['query']) ? "?{$parts['query']}" : '';
  $fragment = isset($parts['fragment']) ? "#{$parts['fragment']}" : '';
  $url = "{$scheme}://{$userpass}{$host}{$port}{$path}{$query}{$fragment}";
  return $url;
}

function getScheme($url) {
  return parse_url($url, PHP_URL_SCHEME);
}

function getHost($url) {
  return parse_url($url, PHP_URL_HOST);
}

function getUrlToPort($url) {
  return getUrlToPortOrPath($url, false);
}

function getUrlHostToPort($url) {
  return getUrlToPortOrPath($url, false, true);
}

function getUrlToPath($url) {
  return getUrlToPortOrPath($url, true);
}

function getUrlToPortOrPath($url, $includePath, $fromHost = false) {
  $parts = parse_url($url);
  if ($fromHost) {
    $scheme = '';
    $userpass = '';
  } else {
    $scheme = isset($parts['scheme']) ? "{$parts['scheme']}://" : '';
    $user = isset($parts['user']) ? $parts['user'] : '';
    $pass = isset($parts['pass']) ? ":{$parts['pass']}" : '';
    $userpass = $user !== '' || $pass !== '' ? "{$user}{$pass}@" : '';
  }
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
