<?php
// Only report errors, do not report warnings
error_reporting(E_ERROR);

// Disable script timeout - timeouts can be handled by the AJAX calling thread instead
ini_set('max_execution_time', 0);

// // Include library for detecting browser
// require_once('url_suffixes.php');

$SERVER_HOST = $_SERVER['HTTP_HOST'];
$SERVER_URL = getServerUrl();
$ESC_SERVER_URL = str_replace('/', '\/', preg_quote($SERVER_URL));

$queryString = $_SERVER['QUERY_STRING'];
$PAYLOAD = file_get_contents('php://input');
if (strlen($queryString) > 0 || $PAYLOAD > 0) {
  if (strStartsWith($queryString, 'pageUrl=')) {
    $ampersandPos = strpos($queryString, '&');
    if ($ampersandPos === false) {
      $PAGE_URL = urldecode(substr($queryString, 8));
    } else {
      $PAGE_URL = urldecode(substr($queryString, 8, $ampersandPos - 8))
        . substr($queryString, $ampersandPos);
    }
  } else if (strStartsWith($PAYLOAD, 'pageUrl=')) {
    $ampersandPos = strpos($PAYLOAD, '&');
    if ($ampersandPos === false) {
      $PAGE_URL = urldecode(substr($PAYLOAD, 8));
      $PAYLOAD = '';
    } else {
      $PAGE_URL = urldecode(substr($PAYLOAD, 8, $ampersandPos - 8));
      $PAYLOAD = substr($PAYLOAD, $ampersandPos + 1);
      $PAYLOAD = convertUrlsInString($PAYLOAD, $PAGE_URL);
    }
  } else {
    $PAGE_URL = urldecode($queryString);
  }
  $PAGE_URL = preprocessAbsoluteUrl($PAGE_URL);
  $PAGE_URL_HOST = getUrlHost($PAGE_URL);
  $PAGE_URL_TO_PORT = getUrlToPort($PAGE_URL);
  $PAGE_URL_TO_PATH = getUrlToPath($PAGE_URL);

  sendResponseForHttpRequest();
} else {
  // Redirect to home page
  header("Location: index.html");
}

// Send an HTTP request (see https://stackoverflow.com/questions/5647461) and respond according to the response 
function sendResponseForHttpRequest() {
  global $SERVER_HOST, $SERVER_URL, $ESC_SERVER_URL, $PAGE_URL_HOST, $PAGE_URL_TO_PORT, $PAGE_URL_TO_PATH;

  $curlResponse = sendHttpRequest();

  $content = $curlResponse['content'];
  $headers = $curlResponse['headers'];
  $newHeaders = convertHeaders($headers);
  sendHeaders($newHeaders);

  $contentType = getContentType($headers);
  $isTextual = preg_match('/(?:text\/|html|json|xml|multipart)/i', $contentType);
  if ($isTextual) {
    $content = convertUrlsInContent($content);
  }
  $isHtml = preg_match('/(?:text\/html|application\/xhtml\+xml)/i', $contentType);
  if ($isHtml) {
    $content = preg_replace('/<\/body\s*>(?!.*<\/body\s*>)/i', <<<EOD

<script>
  const SERVER_HOST = "$SERVER_HOST",
    SERVER_URL = "$SERVER_URL",
    ESC_SERVER_URL = "$ESC_SERVER_URL",
    PAGE_URL_HOST = "$PAGE_URL_HOST",
    PAGE_URL_TO_PORT = "$PAGE_URL_TO_PORT",
    PAGE_URL_TO_PATH = "$PAGE_URL_TO_PATH";
EOD . <<<'EOD'


  document.addEventListener('readystatechange', (event) => {
    document.documentElement.innerHTML = convertUrlsInContent(document.documentElement.innerHTML);
  });

  const unmodifiedOpen = XMLHttpRequest.prototype.open;
  XMLHttpRequest.prototype.open = function(method, url, async, user, password) {
    unmodifiedOpen.call(this, method, convertUrl(url), async, user, password);
  };

  function convertUrlsInContent(content) {
    // href/data-src/src/srcset/url =/: "..."/'...'/`...`/&quot;...&quot;/&apos;...&apos;
    content = content.replaceAll(
      /([:\\s])(href|(?:data-)?src|srcset|url)((?:["'`]|&quot;|&apos;)?\\s*[=:]\\s*)(["\\']|&quot;|&apos;)(?!\${ESC_SERVER_URL})(.*?)\\4/gi,
      function (whole, match1, match2, match3, match4, match5) {
        if (match2 === "srcset") {
          convertedValue = match5.replaceAll(/([^\\s,]*)(.*?(?:,\\s*|\$))/g, function (whole, match1, match2) {
            convertedUrl = convertUrl(match1);
            return `\${convertedUrl}\${match2}`;
          });
        } else {
          convertedValue = convertUrl(match5, ["&quot", "&apos"].includes(match4));
        }
        replaced = `\${match1}\${match2}\${match3}\${match4}\${convertedValue}\${match4}`;
        return replaced;
      }
    );
    // : url ( "..."/'...'/... )
    content = content.replaceAll(
      /(:\\s*url\\s*\\(\\s*)(["'`]|)(?!\${ESC_SERVER_URL})(.*?)\\2(\\s*\\))/gi,
      function (whole, match1, match2, match3, match4) {
        convertedValue = convertUrl(match3);
        replaced = `\${match1}\${match2}\${convertedValue}\${match2}\${match4}`;
        return replaced;
      }
    );
    // <form ...action = "..."/'...'...>
    content = content.replaceAll(
      /(<form\\s+[^>]*)(action\\s*=\\s*)(["'`])(?!\${ESC_SERVER_URL})(.*?)\\2(.*?>)/gi,
      function (whole, match1, match2, match3, match4, match5) {
        absUrl = getAbsoluteUrl(match4);
        appendChar = !absUrl.includes("?") && !absUrl.includes("#") ? "?" : "";
        absUrl = `\${absUrl}\${appendChar}`;
        replaced = `\${match1}\${match2}\${match3}\${SERVER_URL}?\${match3}\${match5}<input type='hidden' name='pageUrl' value='\${absUrl}'/>`;
        return replaced;
      }
    );

    content = content.replaceAll(
      new RegExp(`(?<!xmlns)([=:]\\\\s*)(["'\`]|&quot;|&apos;)(?!\${ESC_SERVER_URL})(https?:\\\\/\\\\/(?!(?:\\\\2)).+?)\\\\2`, "gi"),
      function (whole, match1, match2, match3) {
        convertedValue = convertUrl(match3, ["&quot", "&apos"].includes(match2));
        replaced = `\${match1}\${match2}\${convertedValue}\${match2}`;
        return replaced;
      }
    );
    return content;
  }

  function convertUrl(url, httpEncode) {
    let newUrl;
    if (httpEncode) {
      newUrl = htmlEntityDecode(newUrl);
    } else {
      newUrl = url;
    }
    newUrl = getAbsoluteUrl(newUrl);
    if (!url.startsWith(`\${SERVER_URL}?`) && /^(?:https?:)?\\/\\//i.test(url)) {
      newUrl = encodeURIComponent(newUrl);
      newUrl = newUrl.replace(/%23/, "#");
      newUrl = `\${SERVER_URL}?\${newUrl}`;
    }
    if (httpEncode) {
      newUrl = htmlEntityEncode(newUrl);
    }
    return newUrl;
  }

  function getAbsoluteUrl(url) {
    if (url.startsWith(`\${SERVER_URL}?`) || /^(?:#|about:|data:|javascript:|[^\\/?:]*:?\\/\\/)/i.test(url)) {
      return url;
    } else {
      const pageUrlPart = url.startsWith("/") ? PAGE_URL_TO_PORT : PAGE_URL_TO_PATH;
      return `\${pageUrlPart}\${url}`;
    }
  }

  const textarea = document.createElement("textarea");
  function htmlEntityEncode(html) {
    textarea.textContent = html;
    return textarea.innerHTML;
  }
  function htmlEntityDecode(html) {
    textarea.innerHTML = html;
    return textarea.textContent;
  }
</script>
$0
EOD, $content);
  }

  echo ($content);
}

function sendHttpRequest() {
  global $PAYLOAD, $PAGE_URL, $PAGE_URL_TO_PORT, $PAGE_URL_HOST;
  $curDir = __DIR__;
  $cookieFilenameBase = urlencode(
    "{$_SERVER['REMOTE_ADDR']}-{$_SERVER['HTTP_X_FORWARDED_FOR']}-{$_SERVER['HTTP_USER_AGENT']}"
  );
  $cookieFilename = "{$curDir}/cookies/{$cookieFilenameBase}.txt";

  $ch = curl_init($PAGE_URL);
  $requestMethod = $_SERVER['REQUEST_METHOD'];
  if ($requestMethod !== 'GET') {
    $headers = getallheaders();
    $headers['Referer'] = $PAGE_URL_TO_PORT;
    $headers['Host'] = $PAGE_URL_HOST;
    $headers['Origin'] = $headers['Referer'];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($requestMethod === 'POST') {
      curl_setopt($ch, CURLOPT_POST, true);
    } else {
      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $requestMethod);
    }
    curl_setopt($ch, CURLOPT_POSTFIELDS, $PAYLOAD);
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
    echo ("Failed {$_SERVER['REQUEST_METHOD']} request to {$PAGE_URL}");
    $curlError = curl_error($ch);
    echo ("<br/><br/>{$curlError}");
    curl_close($ch);
    exit();
  } else {
    curl_close($ch);
    return array('content' => $content, 'headers' => $headers);
  }
}

function convertHeaders($headers) {
  global $PAGE_URL;
  $httpEntryFound = false;
  $newHeaders = [];
  foreach ($headers as $header) {
    $header = trim($header);
    if (empty($header)) continue;
    if (preg_match('/^(location:\s*)(.*)/i', $header, $matches)) {
      $PAGE_URL = getUrlToPath(getAbsoluteUrl($matches[2], $PAGE_URL));
      $header = "{$matches[1]}{$PAGE_URL}";
    }

    if (!preg_match('/^(?:content-encoding|content-security-policy|cross-origin|referrer-policy|timing-allow-origin|transfer-encoding)/i', $header)) {
      if (preg_match('/^(link:\s+<)([^>]+)(>.*)/i', $header, $matches)) {
        $url = convertUrl($matches[2], $PAGE_URL);
        $header = "{$matches[1]}{$url}{$matches[3]}";
      }
      $header = convertUrlsInString($header);

      if (preg_match('/^http\//i', $header)) {
        if ($httpEntryFound) {
          $newHeaders = [];
        }
        $httpEntryFound = true;
      }

      $newHeaders[] = $header;
    }
  }

  $newHeaders[] = 'Access-Control-Allow-Origin: *';
  $newHeaders[] = 'Cross-Origin-Resource-Policy: cross-origin';
  $newHeaders[] = 'Referrer-Policy: unsafe-url';
  $newHeaders[] = 'Timing-Allow-Origin: *';
  return $newHeaders;
}

function sendHeaders($headers) {
  foreach ($headers as $header) {
    header($header);
  }
}

function getContentType($httpResponseHeaderArray) {
  $httpResponseHeaderStr = implode("\n", $httpResponseHeaderArray);
  return preg_match_all('/^content-type\s*:\s*(.*)$/mi', $httpResponseHeaderStr, $matches, PREG_SET_ORDER) ?
    end($matches)[1] : NULL;
}

function getCharset($contentType) {
  return preg_match('/;\s*charset\s*=\s*(.*)$/i', $contentType, $matches) ? $matches[1] : '';
}

function convertUrlsInContent($content) {
  global $SERVER_HOST, $SERVER_URL, $ESC_SERVER_URL, $PAGE_URL_HOST;
  $content = str_replace($SERVER_HOST, "{$SERVER_URL}?{$PAGE_URL_HOST}", $content);
  // href/data-src/src/srcset/url =/: "..."/'...'/`...`/&quot;...&quot;/&apos;...&apos;
  $content = preg_replace_callback(
    "/([:\\s])(href|(?:data-)?src|srcset|url)((?:[\"'`]|&quot;|&apos;)?\\s*[=:]\\s*)([\"'`]|&quot;|&apos;)(?!$ESC_SERVER_URL)(.*?)\\4/i",
    function ($matches) {
      if ($matches[2] === 'srcset') {
        $convertedValue = preg_replace_callback(
          '/([^\s,]*)(.*?(?:,\s*|$))/',
          function ($matches) {
            $convertedUrl = convertUrl($matches[1]);
            return "{$convertedUrl}{$matches[2]}";
          },
          $matches[5]
        );
      } else {
        $convertedValue = convertUrl($matches[5], in_array($matches[4], array('&quot', '&apos')));
      }
      $replaced = "{$matches[1]}{$matches[2]}{$matches[3]}{$matches[4]}{$convertedValue}{$matches[4]}";
      return $replaced;
    },
    $content
  );
  // : url ( "..."/'...'/... )
  $content = preg_replace_callback(
    '/(:\s*url\s*\(\s*)(["\']|)(.*?)\2(\s*\))/i',
    function ($matches) {
      $convertedValue = convertUrl($matches[3]);
      $replaced = "{$matches[1]}{$matches[2]}{$convertedValue}{$matches[2]}{$matches[4]}";
      return $replaced;
    },
    $content
  );
  // <form ...action = "..."/'...'...>
  $content = preg_replace_callback(
    "/(<form\\s+[^>]*)(action\\s*=\\s*)([\"'`])(?!$ESC_SERVER_URL)(.*?)\\2(.*?>)/i",
    function ($matches) {
      global $SERVER_URL;
      $absUrl = getAbsoluteUrl($matches[4]);
      $appendChar = !strContains($absUrl, '?') && !strContains($absUrl, '#') ? '?' : '';
      $absUrl = "{$absUrl}{$appendChar}";
      $replaced = "{$matches[1]}{$matches[2]}{$matches[3]}{$SERVER_URL}?{$matches[3]}{$matches[5]}<input type='hidden' name='pageUrl' value='{$absUrl}'/>";
      return $replaced;
    },
    $content
  );

  $content = preg_replace_callback(
    "/(?<!xmlns)([=:]\s*)([\"'`]|&quot;|&apos;)(?!$ESC_SERVER_URL)(https?:\/\/(?!(?:\\2)).+?)\\2/i",
    function ($matches) {
      $convertedValue = convertUrl($matches[3], in_array($matches[2], array('&quot', '&apos')));
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

function convertUrl($url, $httpEncode = false) {
  global $SERVER_URL;
  if ($httpEncode) {
    $newUrl = html_entity_decode($url);
  } else {
    $newUrl = $url;
  }
  $newUrl = getAbsoluteUrl($newUrl);
  if (!strStartsWith($url, "{$SERVER_URL}?") && preg_match('/^(?:https?:)?\/\//i', $url)) {
    $newUrl = urlencode($newUrl);
    $newUrl = preg_replace('/%23/', '#', $newUrl, 1);
    $newUrl = "{$SERVER_URL}?{$newUrl}";
  }
  if ($httpEncode) {
    $newUrl = htmlentities($newUrl);
  }
  return $newUrl;
}

function getAbsoluteUrl($url) {
  global $SERVER_URL, $PAGE_URL_TO_PORT, $PAGE_URL_TO_PATH;
  // Leave the URL unchanged if it begins "#", "about:", "data:", "javascript:", anything followed by :// or is "" or "."
  if (strStartsWith($url, "{$SERVER_URL}?") 
      || preg_match('/^(?:#|about:|data:|javascript:|\.?$|[^\/?:]*:?\/\/)/i', $url)) {
    return $url;
  } else {
    $pageUrlPart = strStartsWith($url, '/') ? $PAGE_URL_TO_PORT : $PAGE_URL_TO_PATH;
    return "{$pageUrlPart}{$url}";
  }
}

function getServerUrl() {
  global $SERVER_HOST;
  $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
  return "{$scheme}://{$SERVER_HOST}{$_SERVER['URL']}";
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
