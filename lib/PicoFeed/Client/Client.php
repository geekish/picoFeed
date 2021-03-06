<?php

namespace PicoFeed\Client;

use DateTime;
use Exception;
use GuzzleHttp\ClientInterface;
use PicoFeed\Logging\Logger;
use PicoFeed\Config\Config;
use Psr\Http\Message\ResponseInterface;

/**
 * Client class.
 *
 * @author  Frederic Guillot
 */
class Client
{
    /**
     * Flag that say if the resource have been modified.
     *
     * @var bool
     */
    private $is_modified = true;

    /**
     * HTTP Content-Type.
     *
     * @var string
     */
    private $content_type = '';

    /**
     * HTTP encoding.
     *
     * @var string
     */
    private $encoding = '';

    /**
     * HTTP request headers.
     *
     * @var array
     */
    protected $request_headers = array();

    /**
     * HTTP Etag header.
     *
     * @var string
     */
    protected $etag = '';

    /**
     * HTTP Last-Modified header.
     *
     * @var string
     */
    protected $last_modified = '';

    /**
     * Expiration DateTime
     *
     * @var DateTime
     */
    protected $expiration = null;

    /**
     * Proxy hostname.
     *
     * @var string
     */
    protected $proxy_hostname = '';

    /**
     * Proxy port.
     *
     * @var int
     */
    protected $proxy_port = 3128;

    /**
     * Proxy username.
     *
     * @var string
     */
    protected $proxy_username = '';

    /**
     * Proxy password.
     *
     * @var string
     */
    protected $proxy_password = '';

    /**
     * Basic auth username.
     *
     * @var string
     */
    protected $username = '';

    /**
     * Basic auth password.
     *
     * @var string
     */
    protected $password = '';

    /**
     * CURL options.
     *
     * @var array
     */
    protected $additional_curl_options = array();

    /**
     * Client connection timeout.
     *
     * @var int
     */
    protected $timeout = 10;

    /**
     * User-agent.
     *
     * @var string
     */
    protected $user_agent = 'PicoFeed (https://github.com/nicolus/picoFeed)';

    /**
     * Real URL used (can be changed after a HTTP redirect).
     *
     * @var string
     */
    protected $url = '';

    /**
     * Page/Feed content.
     *
     * @var string
     */
    protected $content = '';

    /**
     * Number maximum of HTTP redirections to avoid infinite loops.
     *
     * @var int
     */
    protected $max_redirects = 5;

    /**
     * Maximum size of the HTTP body response.
     *
     * @var int
     */
    protected $max_body_size = 2097152; // 2MB

    /**
     * HTTP response status code.
     *
     * @var int
     */
    protected $status_code = 0;

    /**
     * Enables direct passthrough to requesting client.
     *
     * @var bool
     */
    protected $passthrough = false;

    /**
     * Http client used to make requests
     *
     * @var \GuzzleHttp\Client
     */
    private $httpClient;

    /**
     * Do the HTTP request.
     *
     * @return ResponseInterface
     */
    public function doRequest()
    {
        $opts = [
            'timeout' => $this->timeout,
            'allow_redirects' => ['max' => $this->max_redirects],
            'headers' => ['User-Agent' => $this->user_agent],
            'curl' => $this->additional_curl_options,
        ];
        if (strlen($this->last_modified)) {
            $opts['headers']['If-Modified-Since'] = $this->last_modified;
        }
        if (strlen($this->etag)) {
            $opts['headers']['If-None-Match'] = $this->etag;
        }
        if (strlen($this->username)) {
            $opts['auth'] = ['username' => $this->username, 'password' => (string) $this->password];
        }
        if (strlen($this->proxy_hostname)) {
            // Proxies are assumed to be plain HTTP proxies because this is what PicoFeed historically assumed
            $proxy = "http://";
            if (strlen($this->proxy_username)) {
                $proxy .= rawurlencode($this->proxy_username);
                if (strlen($this->proxy_password)) {
                    $proxy .= ":" . rawurlencode($this->proxy_password);
                }
                $proxy .= "@";
            }
            $proxy .= $this->proxy_hostname . ":" . $this->proxy_port;
            $opts['proxy'] = $proxy; 
        }
        return $this->httpClient->get($this->url, $opts);
    }

    public function __construct(ClientInterface $httpClient = null)
    {
        $this->httpClient = $httpClient;
    }


    /**
     * Get client instance: curl or stream driver.
     *
     * @static
     *
     * @return \PicoFeed\Client\Client
     */
    public static function getInstance()
    {
        return new self(new \GuzzleHttp\Client([]));
    }

    /**
     * Add HTTP Header to the request.
     *
     * @param array $headers
     */
    public function setHeaders($headers)
    {
        $this->request_headers = $headers;
    }

    /**
     * Perform the HTTP request.
     *
     * @param string $url URL
     *
     * @return Client
     */
    public function execute($url = '')
    {
        if ($url !== '') {
            $this->url = $url;
        }

        Logger::setMessage(get_called_class() . ' Fetch URL: ' . $this->url);
        Logger::setMessage(get_called_class() . ' Etag provided: ' . $this->etag);
        Logger::setMessage(get_called_class() . ' Last-Modified provided: ' . $this->last_modified);

        $response = $this->doRequest();
        if ($response) {
            if ($this->isPassthroughEnabled()) {
                echo $response->getBody()->getContents();
            };

            $this->handleNotModifiedResponse($response);
            $this->handleNormalResponse($response);
            $this->expiration = $this->parseExpiration($response);
        }


        Logger::setMessage(get_called_class() . ' Expiration: ' . $this->expiration->format(DATE_ISO8601));

        return $this;
    }

    /**
     * Handle not modified response.
     * @param ResponseInterface $response
     */
    protected function handleNotModifiedResponse(ResponseInterface $response)
    {
        if ($response->getStatusCode() == 304) {
            $this->is_modified = false;
        } elseif ($response->getStatusCode() == 200) {
            $this->is_modified = $this->hasBeenModified($response, $this->etag, $this->last_modified);
            $this->etag = $response->getHeader('ETag')[0] ?? null;
            $this->last_modified = $response->getHeader('Last-Modified')[0] ?? null;
        }

        if ($this->is_modified === false) {
            Logger::setMessage(get_called_class() . ' Resource not modified');
        }
    }

    /**
     * Handle normal response.
     *
     * @param ResponseInterface $response Client response
     */
    protected function handleNormalResponse(ResponseInterface $response)
    {
        if ($response->getStatusCode() == 200) {
            $this->content = $response->getBody()->getContents();
            $this->content_type = $this->findContentType($response);
            $this->encoding = $this->findCharset();
        }
    }

    /**
     * Check if a request has been modified according to the parameters.
     *
     * @param ResponseInterface $response
     * @param string $etag
     * @param string $lastModified
     * @return bool
     */
    private function hasBeenModified(ResponseInterface $response, $etag, $lastModified)
    {
        $headers = array(
            'Etag' => $etag,
            'Last-Modified' => $lastModified,
        );

        // Compare the values for each header that is present
        $presentCacheHeaderCount = 0;
        foreach ($headers as $key => $value) {
            if (!empty($response->getHeader($key)[0])) {
                if ($response->getHeader($key)[0] !== $value) {
                    return true;
                }
                ++$presentCacheHeaderCount;
            }
        }

        // If at least one header is present and the values match, the response
        // was not modified
        if ($presentCacheHeaderCount > 0) {
            return false;
        }

        return true;
    }

    /**
     * Find content type from response headers.
     *
     * @param ResponseInterface $response Client response
     * @return string
     */
    public function findContentType(ResponseInterface $response)
    {
        return strtolower($response->getHeader('content-type')[0]);
    }

    /**
     * Find charset from response headers.
     *
     * @return string
     */
    public function findCharset()
    {
        $result = explode('charset=', $this->content_type);
        return isset($result[1]) ? $result[1] : '';
    }

    /**
     * Get header value from a client response.
     *
     * @param array $response Client response
     * @param string $header Header name
     * @return string
     */
    public function getHeader(array $response, $header)
    {
        return isset($response['headers'][$header]) ? $response['headers'][$header][0] : '';
    }

    /**
     * Set the Last-Modified HTTP header.
     *
     * @param string $last_modified Header value
     * @return $this
     */
    public function setLastModified($last_modified)
    {
        $this->last_modified = $last_modified;
        return $this;
    }

    /**
     * Get the value of the Last-Modified HTTP header.
     *
     * @return string
     */
    public function getLastModified()
    {
        return $this->last_modified;
    }

    /**
     * Set the value of the Etag HTTP header.
     *
     * @param string $etag Etag HTTP header value
     * @return $this
     */
    public function setEtag($etag)
    {
        $this->etag = $etag;
        return $this;
    }

    /**
     * Get the Etag HTTP header value.
     *
     * @return string
     */
    public function getEtag()
    {
        return $this->etag;
    }

    /**
     * Get the final url value.
     *
     * @return string
     */
    public function getUrl()
    {
        return $this->url;
    }

    /**
     * Set the url.
     *
     * @param  $url
     * @return string
     */
    public function setUrl($url)
    {
        $this->url = $url;
        return $this;
    }

    /**
     * Get the HTTP response status code.
     *
     * @return int
     */
    public function getStatusCode()
    {
        return $this->status_code;
    }

    /**
     * Get the body of the HTTP response.
     *
     * @return string
     */
    public function getContent()
    {
        return $this->content;
    }

    /**
     * Get the content type value from HTTP headers.
     *
     * @return string
     */
    public function getContentType()
    {
        return $this->content_type;
    }

    /**
     * Get the encoding value from HTTP headers.
     *
     * @return string
     */
    public function getEncoding()
    {
        return $this->encoding;
    }

    /**
     * Return true if the remote resource has changed.
     *
     * @return bool
     */
    public function isModified()
    {
        return $this->is_modified;
    }

    /**
     * return true if passthrough mode is enabled.
     *
     * @return bool
     */
    public function isPassthroughEnabled()
    {
        return $this->passthrough;
    }

    /**
     * Set connection timeout.
     *
     * @param int $timeout Connection timeout
     * @return $this
     */
    public function setTimeout($timeout)
    {
        $this->timeout = $timeout ?: $this->timeout;
        return $this;
    }

    /**
     * Set a custom user agent.
     *
     * @param string $user_agent User Agent
     * @return $this
     */
    public function setUserAgent($user_agent)
    {
        $this->user_agent = $user_agent ?: $this->user_agent;
        return $this;
    }

    /**
     * Set the maximum number of HTTP redirections.
     *
     * @param int $max Maximum
     * @return $this
     */
    public function setMaxRedirections($max)
    {
        $this->max_redirects = $max ?: $this->max_redirects;
        return $this;
    }

    /**
     * Set the maximum size of the HTTP body.
     *
     * @param int $max Maximum
     * @return $this
     */
    public function setMaxBodySize($max)
    {
        $this->max_body_size = $max ?: $this->max_body_size;
        return $this;
    }

    /**
     * Set the proxy hostname.
     *
     * @param string $hostname Proxy hostname
     * @return $this
     */
    public function setProxyHostname($hostname)
    {
        $this->proxy_hostname = $hostname ?: $this->proxy_hostname;
        return $this;
    }

    /**
     * Set the proxy port.
     *
     * @param int $port Proxy port
     * @return $this
     */
    public function setProxyPort($port)
    {
        $this->proxy_port = $port ?: $this->proxy_port;
        return $this;
    }

    /**
     * Set the proxy username.
     *
     * @param string $username Proxy username
     * @return $this
     */
    public function setProxyUsername($username)
    {
        $this->proxy_username = $username ?: $this->proxy_username;
        return $this;
    }

    /**
     * Set the proxy password.
     *
     * @param string $password Password
     * @return $this
     */
    public function setProxyPassword($password)
    {
        $this->proxy_password = $password ?: $this->proxy_password;
        return $this;
    }

    /**
     * Set the username.
     *
     * @param string $username Basic Auth username
     *
     * @return $this
     */
    public function setUsername($username)
    {
        $this->username = $username ?: $this->username;
        return $this;
    }

    /**
     * Set the password.
     *
     * @param string $password Basic Auth Password
     *
     * @return $this
     */
    public function setPassword($password)
    {
        $this->password = $password ?: $this->password;
        return $this;
    }

    /**
     * Set the CURL options.
     *
     * @param array $options
     * @return $this
     */
    public function setAdditionalCurlOptions(array $options)
    {
        $this->additional_curl_options = $options ?: $this->additional_curl_options;
        return $this;
    }


    /**
     * Enable the passthrough mode.
     *
     * @return $this
     */
    public function enablePassthroughMode()
    {
        $this->passthrough = true;
        return $this;
    }

    /**
     * Disable the passthrough mode.
     *
     * @return $this
     */
    public function disablePassthroughMode()
    {
        $this->passthrough = false;
        return $this;
    }

    /**
     * Set config object.
     *
     * @param \PicoFeed\Config\Config $config Config instance
     * @return $this
     */
    public function setConfig(Config $config)
    {
        if ($config !== null) {
            $this->setTimeout($config->getClientTimeout());
            $this->setUserAgent($config->getClientUserAgent());
            $this->setMaxRedirections($config->getMaxRedirections());
            $this->setMaxBodySize($config->getMaxBodySize());
            $this->setProxyHostname($config->getProxyHostname());
            $this->setProxyPort($config->getProxyPort());
            $this->setProxyUsername($config->getProxyUsername());
            $this->setProxyPassword($config->getProxyPassword());
            $this->setAdditionalCurlOptions($config->getAdditionalCurlOptions() ?: array());
        }

        return $this;
    }

    /**
     * Return true if the HTTP status code is a redirection
     *
     * @access protected
     * @param  integer $code
     * @return boolean
     */
    public function isRedirection($code)
    {
        return $code == 301 || $code == 302 || $code == 303 || $code == 307;
    }

    public function parseExpiration(ResponseInterface $response)
    {
        try {

            if ($cacheControl = $response->getHeader('Cache-Control')) {
                $cacheControl = reset($cacheControl);
                if (preg_match('/s-maxage=(\d+)/', $cacheControl, $matches)) {
                    return new DateTime('+' . $matches[1] . ' seconds');
                } else if (preg_match('/max-age=(\d+)/', $cacheControl, $matches)) {
                    return new DateTime('+' . $matches[1] . ' seconds');
                }
            }

            $expires = $response->getHeader('Expires');
            if (is_array($expires) && count($expires) > 0) {
                return new DateTime($expires[0]);
            }
        } catch (Exception $e) {
            Logger::setMessage('Unable to parse expiration date: ' . $e->getMessage());
        }

        return new DateTime();
    }

    /**
     * Get expiration date time from "Expires" or "Cache-Control" headers
     *
     * @return DateTime
     */
    public function getExpiration()
    {
        return $this->expiration ?: new DateTime();
    }
}
