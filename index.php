<?php
  // // Include library for detecting browser
  // require_once('url_suffixes.php');

  // Only report errors, do not report warnings
  error_reporting(E_ERROR);

  // Disable script timeout - timeouts can be handled by the AJAX calling thread instead
  ini_set('max_execution_time', 0);

  ob_start();
  $queryString = $_SERVER['QUERY_STRING'];
  if (strlen($queryString) > 0) {
    echo(sendHttpRequest($queryString, 'GET'));
  } else {
    // Redirect to home page
    header("Location: index.html");
  }
  ob_flush();

  // Send an HTTP request - see https://stackoverflow.com/questions/5647461
  function sendHttpRequest($url, $method, $content = null, $header = null)
  {
    $url = preprocessAbsoluteUrl($url);
    $options = array('http' => array('method' => $method));
    if ($content !== null) {
      $options['http']['content'] = $content;
    }
    if ($header !== null) {
      $options['http']['header'] = $headers;
    }
    $context = stream_context_create($options);
    $response = file_get_contents($url, false, $context);
    if ($response === false) {
      throw new Exception("Problem reading data from $url: $php_errormsg");
    }
    //print("<pre>".print_r($http_response_header, true)."</pre>");
    $contentType = getContentType($http_response_header);
    if ((strpos($contentType, 'text/html') !== false)) {
      $dom = new DOMDocument();
      $dom->loadHTML($response);
      convertLinks($dom, $url);
      return $dom->saveHTML();
    } else {
      return $response;
    }
  }

  function getContentType($httpResponseHeader) {
    $pattern = '/^Content-Type\s*:\s*(.*)$/i';
    if (($matchingElements = array_values(preg_grep($pattern, $httpResponseHeader))) &&
        (preg_match($pattern, $matchingElements[0], $matches) !== false)) {
      return $matches[1];
    } else {
      return NULL;
    }
  }

  function convertLinks(DOMNode $domNode, $pageUrl) {
    foreach ($domNode->childNodes as $node)
    {
      if ($node instanceof DOMElement) {
        switch ($node->tagName) {
          case 'img':
            $src = trim($node->getAttribute('src'));
            if ($src !== '') {
              $node->setAttribute('src', getConvertedLinkUrl($src, $pageUrl));
            }
            $datasrc = trim($node->getAttribute('data-src'));
            if ($datasrc !== '') {
              $node->setAttribute('data-src', getConvertedLinkUrl($datasrc, $pageUrl));
            }
            break;
          case 'a':
            $href = trim($node->getAttribute('href'));
            if ($href !== '' && substr($href, 0, 11) !== 'javascript:') {
              $node->setAttribute('href', getConvertedLinkUrl($href, $pageUrl));
            }
            break;
        }
      }
      //print $node->nodeName.':'.$node->nodeValue;
      if($node->hasChildNodes()) {
        convertLinks($node, $pageUrl);
      }
    }    
  }

  function getConvertedLinkUrl($linkUrl, $pageUrl) {
    $linkUrl = trim($linkUrl);
    $parts = parse_url($linkUrl);
    // $linkDomain = getDomain($linkUrl);
    // $pageDomain = getDomain($pageUrl);
    // $sameDomain = $linkDomain === $pageDomain;
    // if ((isset($parts['scheme']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['host'])
    //     || isset($parts['port'])) && !$sameDomain) {
    //   return $linkUrl;
    // } else {
      // if (!$sameDomain) {
        if (substr($linkUrl, 0, 1) === '/') {
          $pageUrlUpToDomain = getUrlUpToDomain($pageUrl);
          $linkUrl = "{$pageUrlUpToDomain}{$linkUrl}";
        // } else {
        //   $pageUrlUpToPath = getUrlUpToPath($pageUrl);
        //   $linkUrl = "{$pageUrlUpToPath}/{$linkUrl}";
        }
      // }
      $serverUrl = getServerUrl();
      return "{$serverUrl}?{$linkUrl}";
    // }
  }

  function getServerUrl() {
    $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http"; 
    return "{$scheme}://{$_SERVER['HTTP_HOST']}{$_SERVER['URL']}";
  }

  function preprocessAbsoluteUrl($url) {
    $scheme = getUrlScheme($url);
    if ($scheme === '') {
      $urlNoLeadingSlashes = rtrim(ltrim($url, '/'));
      return "http://{$urlNoLeadingSlashes}";
    } else {
      return trim($url);
    }
  }

  function getDomain($url) {
    global $URL_SUFFIXES_REGEX;
    $host = getUrlHost($url);
    if (preg_match($host, $URL_SUFFIXES_REGEX, $matches)) {
      return $matches[1];
    } else {
      return '';
    }
  }

  function getUrlUpToDomain($url) {
    return getUrlUpToDomainOrPath($url, false);
  }
  
  function getUrlUpToPath($url) {
    return getUrlUpToDomainOrPath($url, true);
  }

  function getUrlUpToDomainOrPath($url, $includePath) {
    $parts = parse_url($url);
    $scheme = isset($parts['scheme']) ? "{$parts['scheme']}://" : '';
    $user = isset($parts['user']) ? $parts['user'] : '';
    $pass = isset($parts['pass']) ? ":{$parts['pass']}" : '';
    $userpass = $user !== '' || $pass !== "{$user}{$pass}@" ? '' : '';
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
?>