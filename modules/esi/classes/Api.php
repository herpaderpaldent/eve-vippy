<?php
namespace esi;

class Api extends \api\Client
{
    /** @var \sso\model\Token */
    public $token = null;

    function __construct($baseURL=false)
    {
        if (!$baseURL)
            $baseURL = \Config::getCONFIG()->get("esi_url");

        parent::__construct($baseURL);
    }

    function setToken(\crest\model\Token $token)
    {
        $this->token = $token;
    }

    function get($url, $params=[])
    {
        $this->resetheader();
        if ($this->token) {
            if ($this->token->isExpired())
                $this->token->refresh();
            $this->addHeader("Authorization: Bearer ".$this->token->accessToken);
        }

        $result = parent::get($url, $params);
        if (!$this->success())
            $this->log("get", $url, $params);
        return $result;
    }

    function post($url, $params=[])
    {
        $this->resetheader();

        if ($this->token) {
            if ($this->token->isExpired())
                $this->token->refresh();
            $this->addHeader("Authorization: Bearer ".$this->token->accessToken);
        }

        $result = parent::post($url, $params);
        if (!$this->success())
            $this->log("post", $url, $params);
        return $result;
    }

    function getResult()
    {
        $result = parent::getResult();
        if (!is_object($result))
            $result = json_decode($result);

        return $result;
    }

    function log($type, $url, $params)
    {
        $content = ($params)?$params:null;
        if (is_object($content) || is_array($content))
            $content = json_encode($content);

        $response = $this->getResult();
        if (is_object($response) || is_array($response))
            $response = json_encode($response);

        \MySQL::getDB()->insert("esi_log", [
            "requesttype" => strtolower($type),
            "url" => $url,
            "httpstatus" => ($this->httpStatus)?$this->httpStatus:null,
            "content" => $content,
            "response" => $response,
            "requestdate" => date("Y-m-d H:i:s")
        ]);
    }
}