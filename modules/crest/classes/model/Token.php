<?php
namespace crest\model;

class Token extends \Model
{
    protected $_keyfield = ["tokentype","tokenid"];

    public $tokenType;
    public $tokenID;
    public $ownerHash;
    public $state;
    public $accessToken;
    public $refreshToken;
    public $expires;
    public $scopes;
    public $updateDate;

    function store()
    {
        $this->updateDate = date("Y-m-d H:i:s");
        parent::store();
    }


    /**
     * Still valid? Can we still use this token?
     * @return bool
     */
    function isValid()
    {
        if (!$this->refreshToken)
            return false;

        if ($this->isExpired())
            return false;

        return true;
    }

    /**
     * Has this token expired?
     * @return bool
     */
    function isExpired()
    {
        if ($this->expires) {
            if (strtotime("now") >= strtotime($this->expires))
                return true;
        }
        return false;
    }
}