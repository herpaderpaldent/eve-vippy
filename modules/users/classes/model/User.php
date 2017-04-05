<?php
namespace users\model;

class User
{
    public $id = 0;
    public $username;
    public $displayname;
    public $password;
    public $loginkey;
    public $email;
    public $deleted = false;
    public $mainCharId = 0;
    public $updatedate;
    public $isAdmin = null;
    public $isDirector = false;
    public $isCEO = false;
    public $isValid = null;

    private $config = null;
    private $groups = null;
    private $visibleUserIDs = null;
    private $rights = null;
    private $permissions = null;
    private $settings = null;
    private $chains = null;
    private $characters = null;
    private $corporations = array();
    private $alliances = array();
    private $authorizedCorporations = null;
    private $authorizedAlliances = null;
    private $capitalShips = null;
    private $logs = null;
    private $notifications = null;

    private $authGroups = null;
    private $authGroupIDs = array();
    private $currentAuthGroup = null;
    private $_accessLists = null;

    /** @var \eve\model\Character */
    private $character = null;

    /** @var \eve\model\Character */
    private $_scanalt = null;


    public function __construct($id=false)
    {
        if ($id) {
            $this->id = $id;
            $this->load();
        }
    }

    /**
     * get cache dir
     * @param bool $full
     * @return null|string
     */
    private function getCacheDirectory($full=false)
    {
        if ($this->id !== 0)
        {
            $directory = "user/".$this->id."/";
            if ($full)
                $directory = \Cache::file()->getDirectory().$directory;

            return $directory;
        }

        return null;
    }

    public function resetCache()
    {
        \AppRoot::debug($this->getFullName()."->resetCache()", true);
        \Tools::deleteDir($this->getCacheDirectory(true));
    }

    function load($resultset=false)
    {
        if (!$resultset)
        {
            $cacheFileName = $this->getCacheDirectory()."user.json";

            // Eerst in cache kijken
            if ($cache = \Cache::file()->get($cacheFileName))
                $resultset = json_decode($cache, true);
            else {
                $resultset = \MySQL::getDB()->getRow("SELECT * FROM users WHERE id = ?", array($this->id));
                \Cache::file()->set($cacheFileName, json_encode($resultset));
            }
        }

        if ($resultset)
        {
            $this->id = $resultset["id"];
            $this->username = $resultset["username"];
            $this->displayname = $resultset["displayname"];
            $this->password = $resultset["password"];
            $this->loginkey = $resultset["loginkey"];
            $this->email = $resultset["email"];
            $this->mainCharId = $resultset["mainchar"];
            $this->isDirector = $resultset["isdirector"];
            $this->isCEO = $resultset["isceo"];
            $this->deleted = ($resultset["deleted"]>0)?true:false;
            $this->updatedate = $resultset["updatedate"];
            $this->isValid = null;
        }
    }

    public function addLog($what, $whatid=null, $extrainfo=null, $currentPilot=null, $sessionID=null)
    {
        if ($this->id == 0)
            return false;

        if (!$sessionID)
            $sessionID = session_id();
        if (is_array($extrainfo))
            $extrainfo = json_encode($extrainfo);

        $checkForDoubles = true;
        if (in_array($what, ["delete-wormhole","add-wormhole"]))
            $checkForDoubles = false;

        if ($checkForDoubles)
        {
            $query = ["what = '".$what."'"];
            if ($whatid === null)
                $query[] = "whatid is null";
            else
                $query[] = "whatid = '".$whatid."'";

            if ($result = \MySQL::getDB()->getRow("select * from user_log
                                                   where sessionid = ? and logdate > ?
                                                   and ".implode(" and ", $query)
                                        , [$sessionID, date("Y-m-d")." 00:00:00"]))
            {
                \MySQL::getDB()->update("user_log", ["lastdate" => date("Y-m-d H:i:s")], ["id" => $result["id"]]);
                return true;
            }
        }

        $ipAddress = (isset($_SERVER["REMOTE_ADDR"]))?$_SERVER["REMOTE_ADDR"]:null;
        $userAgent = (isset($_SERVER["HTTP_USER_AGENT"]))?$_SERVER["HTTP_USER_AGENT"]:null;
        if (\AppRoot::isCommandline()) {
            $ipAddress = "localhost";
            $userAgent = "CommandLine";
        }

        \MySQL::getDB()->insert("user_log", [
            "userid"	=> $this->id,
            "pilotid"	=> $currentPilot,
            "lastdate"	=> date("Y-m-d H:i:s"),
            "logdate"	=> date("Y-m-d H:i:s"),
            "what"		=> $what,
            "whatid"    => $whatid,
            "ipaddress"	=> $ipAddress,
            "sessionid"	=> $sessionID,
            "useragent"	=> $userAgent,
            "extrainfo" => $extrainfo
        ]);
        return true;
    }

    public function setLoginStatus($loggedin=true, $setKeyCookie=false)
    {
        \AppRoot::debug("setLoginStatus(".$loggedin."): ".$this->id);
        \User::unsetUser();
        if ($loggedin)
        {
            $this->resetCache();
            $this->fetchIsAuthorized();
            \User::setUSER($this);

            if ($setKeyCookie)
                \Tools::setCOOKIE("vippy", $this->createLoginKey());

            // Controleer main character
            if ($this->getMainCharacter()) {
                if (!$this->getMainCharacter()->isAuthorized())
                    $this->resetMainCharacter();
            }
        }
    }

    public function logout()
    {
        \AppRoot::debug("Logout");
        \Tools::unsetCOOKIE("vippy");
        session_destroy();
        session_start();
    }

    public function login($username, $password, $retry=false, $setKeyCookie=false)
    {
        \AppRoot::debug("LOGIN: ".$username." ($retry)");
        if ($users = \MySQL::getDB()->getRows("SELECT * FROM users WHERE username = ? AND deleted = 0", [$username]))
        {
            foreach ($users as $key => $user)
            {
                if ($user["password"] == \User::generatePassword($password, $user["password"]))
                {
                    $this->load($user);
                    $this->setLoginStatus(true, $setKeyCookie);
                    return true;
                }
            }
        }
        return false;
    }

    public function createLoginKey()
    {
        $this->loginkey = sha1($this->username.$this->id);
        $this->loginkey = sha1($this->loginkey.$this->password);
        $this->store();
        return $this->loginkey;
    }

    public function getFullName($reversed=false)
    {
        $ticker = "";
        if ($this->getMainCharacter() != null)
        {
            if ($this->getMainCharacter()->getCorporation())
            {
                $ticker = $this->getMainCharacter()->getCorporation()->ticker;

                if ($this->getMainCharacter()->isCEO())
                    $ticker .= " CEO";
                else if ($this->getMainCharacter()->isDirector())
                    $ticker .= " DIR";
            }

            return ((strlen(trim($ticker)) > 0)?"[".$ticker."] ":"").$this->getMainCharacter()->name;
        }

        if (strlen(trim($this->displayname)) > 0)
            return $this->displayname;

        return $this->username;
    }

    public function store()
    {
        if ($this->username == null)
            return false;


        $user = array();
        $user["username"] = $this->username;
        $user["displayname"] = $this->getFullName();
        $user["password"] = $this->password;
        $user["loginkey"] = $this->loginkey;
        $user["email"] = $this->email;
        $user["deleted"] = ($this->deleted)?1:0;
        $user["mainchar"] = $this->mainCharId;
        $user["isdirector"] = ($this->isDirector)?1:0;
        $user["isceo"] = ($this->isCEO)?1:0;
        $user["isvalid"] = ($this->isAuthorized())?1:0;
        $user["updatedate"] = date("Y-m-d H:i:s");

        if ($this->id != 0)
            $user["id"] = $this->id;

        $result = \MySQL::getDB()->updateinsert("users", $user, array("id" => $this->id));
        if ($this->id == 0)
        {
            $this->id = $result;
            $this->setConfig("patchnotes", strtotime("now"));
        }

        // Zet lidmaatschap gebruikersgroepen
        if ($this->groups !== null)
        {
            \MySQL::getDB()->delete("user_user_group", array("userid" => $this->id));
            foreach ($this->getUserGroups() as $group) {
                \MySQL::getDB()->insert("user_user_group", array("userid" => $this->id, "groupid" => $group->id));
            }
        }

        // User settings
        if ($this->settings !== null)
        {
            \MySQL::getDB()->delete("user_user_settings", ["userid" => $this->id]);
            foreach ($this->settings as $id => $setting)
            {
                if ($setting->value != null)
                {
                    \MySQL::getDB()->insert("user_user_settings", [
                        "settingid" => $setting->setting->id,
                        "userid" => $this->id,
                        "value" => $setting->value
                    ]);
                }
            }
        }

        // Clear user cache
        $this->resetCache();
        return true;
    }

    /**
     * Is this user logged in?
     * @return boolean
     */
    public function loggedIn()
    {
        if (\User::getUSER()) {
            if (\User::getUSER()->id == $this->id)
                return true;
        }
        return false;
    }

    /**
     * Fetch rights
     */
    private function fetchRights()
    {
        if ($this->id == 0)
        {
            $this->rights = array();
            return null;
        }

        $cacheFilename = $this->getCacheDirectory()."rights.json";
        if ($cache = \Cache::file()->get($cacheFilename))
            $this->rights = json_decode($cache,true);
        else
        {
            \AppRoot::debug("User()->fetchRights()");
            $this->rights = array();
            $rights = \MySQL::getDB()->getRows("SELECT  r.id, r.module, r.name, r.title
                                                FROM    user_rights r
                                                    INNER JOIN user_group_rights rg ON rg.rightid = r.id
                                                    INNER JOIN user_user_group ug ON ug.groupid = rg.groupid
                                                    INNER JOIN user_groups g ON g.id = rg.groupid
                                                WHERE   ug.userid = ?
                                                AND		rg.level > 0
                                                GROUP BY r.id"
                                    , array($this->id));
            foreach ($rights as $right) {
                $this->rights[$right["module"]][$right["name"]] = true;
            }
            \Cache::file()->set($cacheFilename, json_encode($this->rights));
        }
    }

    /**
     *
     * Get permission
     * @param string $module
     * @param string $right
     * @return boolean
     */
    public function hasRight($module, $right="allowed")
    {
        \AppRoot::debug("hasRight($module, $right)");
        $cacheFilename = $this->getCacheDirectory()."permissionss.json";

        if ($this->permissions == null)
        {
            $this->permissions = array();

            // Check de cache
            $cache = \Cache::file()->get($cacheFilename);
            if ($cache) {
                $this->permissions = json_decode($cache,true);
            }
        }

        if (!isset($this->permissions[$module][$right]))
        {
            $this->permissions[$module][$right] = $this->calcHasRight($module, $right);
            \Cache::file()->set($cacheFilename, json_encode($this->permissions));
        }

        \AppRoot::debug("/hasRight($module, $right): ".(($this->permissions[$module][$right])?"<span style='color:green;'>true</span>":"<span style='color:red;'>false</span>"));
        return $this->permissions[$module][$right];
    }

    private function calcHasRight($module, $right)
    {
        $moduleObject = null;
        $moduleClass = '\\'.$module.'\\Module';
        if (class_exists($moduleClass))
            $moduleObject = new $moduleClass();

        \AppRoot::debug(\Approot::$config);
        $modulePublic = \AppRoot::config($module."public");
        if ($moduleObject != null)
            $modulePublic = $moduleObject->public;

        if ($module == "admin")
            $modulePublic = true;

        if ($right == "allowed")
        {
            if ($moduleObject != null)
                return $moduleObject->isAvailable();

            $right = "availible";
        }

        if ($right == "availible")
        {
            // Is de module enabled
            if (\AppRoot::config($module."enabled") == false)
                return false;

            // Kijk of de module public is? Dan heb je geen rechten nodig!
            if ($modulePublic)
                return true;
        }

        if (!is_array($this->rights))
            $this->fetchRights();

        // Check op systeem beheer. Een systeem beheerder mag alles
        if (isset($this->rights["admin"]["sysadmin"]))
        {
            if ($this->rights["admin"]["sysadmin"] == true)
            {
                \AppRoot::debug("SYSADMIN");
                return true;
            }
        }

        // Check of de module public is.
        if ($modulePublic)
        {
            \AppRoot::debug($module."-".$right." is public");

            // Ja, iedereen mag deze module.
            if ($right == "availible")
                return true;

            // Je auth-groep mag het wel, maar mag jij het?
            if (isset($this->rights[$module][$right]))
            {
                if ($this->rights[$module][$right] == true)
                    return true;
            }

            \AppRoot::debug($module."-".$right." check dir roles");
            // Check of recht geimpliceerd wordt door director roles
            $rights = \AppRoot::config($module."rights");
            \AppRoot::debug("<pre>".print_r($rights,true)."</pre>");
            if (isset($rights[$right]) && isset($rights[$right]["dirdefault"]))
            {
                \AppRoot::debug("dir is default");
                if ($rights[$right]["dirdefault"] == true && $this->getIsDirector())
                    return true;
            }
        }
        else
        {
            \AppRoot::debug($module."-".$right." is <u>not</u> public");

            // Nee, je auth-groep moet de module ook mogen.
            foreach ($this->getAuthGroupsIDs() as $groupID)
            {
                if ($results = \MySQL::getDB()->getRows("SELECT *
                                                    FROM 	user_auth_groups_modules
                                                    WHERE	authgroupid = ?
                                                    AND		module = ?"
                                        , array($groupID, $module)))
                {
                    foreach ($results as $result)
                    {
                        // Kijk of het public is voor jou authgroup, dan mag het!
                        if ($right == "availible" && $result["public"] > 0)
                            return true;

                        // Je auth-groep mag het wel, maar mag jij het?
                        if (isset($this->rights[$module][$right]))
                        {
                            if ($this->rights[$module][$right] == true)
                                return true;
                        }
                    }
                }
            }
            \AppRoot::debug("<span style='color:red;'>module ".$module." not allowed in authgroup</span>");
        }

        return false;
    }

    /**
     * Get setting
     * @param string $name
     * @return string|null
     */
    public function getSetting($name)
    {
        \AppRoot::debug("User->getSetting($name)");
        if ($this->settings === null)
            $this->fetchSettings();

        if (isset($this->settings[$name]))
            return $this->settings[$name]->value;

        return null;
    }

    /**
     * Set setting
     * @param Setting $setting
     * @param $value
     */
    public function setSetting(\users\model\Setting $setting, $value)
    {
        if ($this->settings === null)
            $this->fetchSettings();

        $this->settings[$setting->name] = new \stdClass();
        $this->settings[$setting->name]->setting = $setting;
        $this->settings[$setting->name]->value = $value;
    }

    public function clearSettings()
    {
        $this->settings = [];
    }

    private function fetchSettings()
    {
        $this->clearSettings();
        $cacheFilename = $this->getCacheDirectory()."settings.json";
        if ($cache = \Cache::file()->get($cacheFilename))
            $results = json_decode($cache,true);
        else
        {
            $results = \MySQL::getDB()->getRows("SELECT	s.*, u.value
                                                FROM    users_setting s
                                                    INNER JOIN user_user_settings u ON u.settingid = s.id
                                                WHERE   u.userid = ?"
                                    , array(\User::getUSER()->id));
            \Cache::file()->set($cacheFilename, json_encode($results));
        }

        if ($results)
        {
            foreach ($results as $result)
            {
                $setting = \users\model\Setting::getObjectByName($result["name"]);
                $setting->load($result);
                $this->setSetting($setting, $result["value"]);
            }
        }
    }

    /**
     * Get scan alt
     * @return \eve\model\Character|null
     */
    function getScanAlt()
    {
        if ($this->_scanalt === null)
        {
            if ($this->getSetting("scanalt"))
                $this->_scanalt = new \eve\model\Character($this->getSetting("scanalt"));
        }

        return $this->_scanalt;
    }


    /**
     * Get config
     * @param string $var
     * @return string|null
     */
    public function getConfig($var)
    {
        if ($this->config === null)
        {
            $this->config = array();
            if ($results = \MySQL::getDB()->getRows("SELECT * FROM user_config WHERE userid = ?", array($this->id)))
            {
                foreach ($results as $result) {
                    $this->config[$result["var"]] = $result["val"];
                }
            }
        }

        if (isset($this->config[$var]))
            return $this->config[$var];

        return null;
    }

    public function setConfig($var, $val)
    {
        if (is_object($val) || is_array($val))
            $val = json_encode($val);

        $this->config[$var] = $val;
        \MySQL::getDB()->updateinsert("user_config",
                                array("userid" => $this->id, "var" => $var, "val" => $val),
                                array("userid" => $this->id, "var" => $var));
    }

    public function clearUserGroups()
    {
        $this->groups = array();
    }

    public function addUserGroup($groupID)
    {
        if ($this->groups === null)
            $this->getUserGroups();

        $usergroup = new \users\model\UserGroup($groupID);

        // Kan deze user wel in deze groep?
        if ($usergroup->getAuthgroup() !== null)
        {
            if (!in_array($usergroup->authGroupID, $this->getAuthGroupsIDs()))
                return false;
        }

        $this->groups[] = $usergroup;
        return true;
    }

    public function removeUserGroup($groupID)
    {
        foreach ($this->getUserGroups() as $key => $group)
        {
            if ($group->id == $groupID)
                unset($this->groups[$key]);
        }
    }

    /**
     * get usergroups
     * @return \users\model\UserGroup[]
     */
    public function getUserGroups()
    {
        if ($this->groups == null)
        {
            \AppRoot::debug("\User(".$this->id.")->getUserGroups()");
            $this->groups = array();
            $groups = \MySQL::getDB()->getRows("SELECT groupid FROM user_user_group WHERE userid = ?", array($this->id));
            foreach ($groups as $group) {
                $this->addUserGroup($group["groupid"]);
            }
        }

        return $this->groups;
    }

    public function inGroup($groupID)
    {
        \AppRoot::debug("\User(".$this->id.")->inGroup(".$groupID.")");
        foreach ($this->getUserGroups() as $group)
        {
            if ($group->id == $groupID)
            {
                return true;
                break;
            }
        }
        return false;
    }

    /**
     * Get characters
     * @return \eve\model\Character[]
     */
    public function getCharacters()
    {
        if ($this->id == 0)
            return array();

        if ($this->characters == null) {
            $controller = new \eve\controller\Character();
            $this->characters = $controller->getUserCharacters($this->id);
        }
        return $this->characters;
    }

    /**
     * reset main character
     */
    public function resetMainCharacter()
    {
        \AppRoot::doCliOutput("resetMainCharacter");
        $this->mainCharId = 0;
        $this->character = null;

        // Welke characters mogen we allemaal als main gebruiken?
        foreach ($this->getAuthorizedCharacters(false) as $char) {
            if ($char->isAuthorized()) {
                \AppRoot::debug("-> ".$char->name);
                if ($this->character) {
                    if ($char->isCEO())
                        $this->character = $char;
                }
                else
                    $this->character = $char;
            }
        }

        // Geen toon gevonden..?
        if (!$this->character) {
            \AppRoot::debug("No toon yet, find any");
            // Dan mag deze gebruiker eigenlijk helemaal niet in VIPPY. Zet toch maar iets.
            foreach ($this->getCharacters() as $char) {
                $this->character = $char;
                break;
            }
        }

        if ($this->character != null) {
            $this->mainCharId = $this->character->id;
            $this->store();
            return $this->character;
        }

        return null;
    }

    /**
     * Get main character
     * @return \eve\model\Character|null
     */
    public function getMainCharacter()
    {
        \AppRoot::debug("getMainCharacter()");
        if ($this->character == null)
        {
            \AppRoot::debug("fetch main characeter");
            if ($this->mainCharId == 0)
                $this->resetMainCharacter();

            if ($this->mainCharId > 0)
                $this->character = new \eve\model\Character($this->mainCharId);
        }

        return $this->character;
    }

    /**
     * Get main character id
     * @return number|null
     */
    public function getMainCharacterID()
    {
        if ($this->getMainCharacter())
            return $this->getMainCharacter()->id;

        return null;
    }

    public function setMainCharacter($characterID)
    {
        $this->mainCharId = $characterID;
        $this->updateDisplayName();
    }

    /**
     * Get capital ships
     * @return \profile\model\Capital[]
     */
    public function getCapitalShips()
    {
        \AppRoot::debug("getCapitalShips()");
        if ($this->capitalShips === null)
        {
            $this->capitalShips = array();
            $cacheFilename = $this->getCacheDirectory()."capitals.json";
            if ($cache = \Cache::file()->get($cacheFilename))
            {
                foreach (json_decode($cache) as $ship)
                {
                    $cap = new \profile\model\Capital();
                    foreach ($ship as $var => $val) {
                        $cap->$var = $val;
                    }
                    $this->capitalShips[] = $cap;
                }
            }
            else
            {
                $this->capitalShips = \profile\model\Capital::findAll(["userid" => $this->id]);
                \Cache::file()->set($cacheFilename, json_encode($this->capitalShips));
            }
        }

        return $this->capitalShips;
    }

    public function getIsSysAdmin()
    {
        return $this->hasRight("admin", "sysadmin");
    }

    /**
     * Is a director?
     * @param bool|string $corpid |false
     * @return bool
     */
    public function getIsDirector($corpid=false)
    {
        if ($this->getIsSysAdmin())
            return true;

        foreach ($this->getAuthorizedCharacters() as $char)
        {
            if ($corpid)
            {
                if ($char->corporationID != $corpid)
                    continue;
            }

            if ($char->isDirector)
                return true;
            if ($char->isCEO)
                return true;
        }

        return false;
    }

    /**
     * Is user vippy admin?
     * @param bool $corpid
     * @return bool
     */
    public function isAdmin($corpid=false)
    {
        if ($this->isAdmin === null)
        {
            $this->isAdmin = false;

            if ($this->getCurrentAuthGroup()) {
                if (!$this->getCurrentAuthGroup()->getConfig("dir_admin_disabled")) {
                    if ($this->getIsDirector($corpid))
                        $this->isAdmin = true;
                }
            }
            if ($this->hasRight("admin", "admin"))
                $this->isAdmin = true;
        }

        return $this->isAdmin;
    }

    /**
     * Is ceo?
     * @param bool|string $corpid
     * @return bool
     */
    public function getIsCEO($corpid=false)
    {
        if ($this->getIsSysAdmin())
            return true;

        foreach ($this->getAuthorizedCharacters() as $char) {
            if ($corpid) {
                if ($char->corporationID != $corpid)
                    continue;
            }
            if ($char->isCEO)
                return true;
        }

        return false;
    }

    /**
     * DEPRICATED
     * @param \scanning\model\Chain $chain
     * @param $action
     * @return mixed
     */
    public function isAllowedChainAction(\scanning\model\Chain $chain, $action)
    {
        \AppRoot::debug("User()->isAllowedChainAction($action)");
        $actions = new \stdClass();

        // Check cache
        $cacheFilename = $this->getCacheDirectory() . "chain-actions.json";
        if ($cache = \Cache::file()->get($cacheFilename))
            $actions = json_decode($cache);

        if (!isset($actions->$action))
        {
            $actions->$action = true;

            if (!$this->isAdmin())
            {
                if ($chain->getSetting('control-' . $action))
                {
                    // Restricted! Check usergroups
                    if (!$this->inGroup($chain->getSetting('control-' . $action)))
                        $actions->$action = false;
                }
            }

            \Cache::file()->set($cacheFilename, json_encode($actions));
        }

        return $actions->$action;
    }

    /**
     * get authorized characters
     * @param bool $fromCache
     * @return \eve\model\Character[]
     */
    public function getAuthorizedCharacters($fromCache=true)
    {
        \AppRoot::debug("User()->getAuthorizedCharacters($fromCache)");

        // Check cache
        $characters = [];
        $cacheFilename = $this->getCacheDirectory() . "authchars.json";
        if ($fromCache) {
            if ($cache = \Cache::file()->get($cacheFilename)) {
                \AppRoot::debug("Load from cache");
                $characters = json_decode($cache);
            }
        }

        if (count($characters) == 0)
        {
            \AppRoot::debug("No authorized characters found. Check again!");
            $characters = array();
            foreach ($this->getCharacters() as $character) {
                if ($character->isAuthorized(true))
                    $characters[] = $character;
            }
            \Cache::file()->set($cacheFilename, json_encode($characters));
        }

        return $characters;
    }

    public function updateDisplayName()
    {
        $this->isCEO = $this->getIsCEO();
        $this->isDirector = ($this->isCEO) ? true : $this->getIsDirector();

        $ticker = $this->getMainCorporation()->ticker;
        if ($this->getIsCEO($this->getMainCorporationID()))
            $ticker .= " CEO";
        else if ($this->getIsDirector($this->getMainCorporationID()))
            $ticker .= " DIR";

        $this->displayname = "[".$ticker."] ".$this->getMainCharacter()->name;
        $this->store();
    }

    /**
     * Get main corporation
     * @return \eve\model\Corporation
     */
    public function getMainCorporation()
    {
        return $this->getMainCharacter()->getCorporation();
    }

    public function getMainCorporationID()
    {
        return $this->getMainCorporation()->id;
    }

    /**
     * Haal users alliances en corporations
     */
    private function fetchCorporations()
    {
        $this->corporations = array();
        $this->alliances = array();
        if ($results = \MySQL::getDB()->getRows("SELECT corp.id, corp.allianceid
                                                FROM    characters ch
                                                    INNER JOIN corporations corp ON corp.id = ch.corpid
                                                WHERE	ch.userid = ?
                                                GROUP BY corp.id"
                                    , array($this->id)))
        {
            foreach ($results as $result)
            {
                if (trim($result["id"])-0 > 0 && !in_array($result["id"], $this->corporations))
                    $this->corporations[] = $result["id"];

                if (trim($result["allianceid"])-0 > 0 && !in_array($result["allianceid"], $this->alliances))
                    $this->alliances[] = $result["allianceid"];
            }
        }
    }

    public function getCorporations()
    {
        if (count($this->corporations) == 0)
            $this->fetchCorporations();

        return $this->corporations;
    }

    public function getAlliances()
    {
        if (count($this->alliances) == 0)
            $this->fetchCorporations();

        return $this->alliances;
    }


    private function fetchAuthorizedCorporationsAlliances()
    {
        \AppRoot::debug("User->fetchAuthorizedCorporationsAlliances()");
        $this->authorizedCorporations = array();
        $this->authorizedAlliances = array();

        // Check cache
        $cacheFilename = $this->getCacheDirectory()."authcorps.json";
        if ($cache = \Cache::file()->get($cacheFilename))
        {
            $cache = json_decode($cache, true);
            foreach ($cache["corporations"] as $data)
            {
                $corporation = new \eve\model\Corporation();
                foreach ($data as $var => $val) {
                    $corporation->$var = $val;
                }
                $this->authorizedCorporations[] = $corporation;
            }
            foreach ($cache["alliances"] as $data)
            {
                $alliance = new \eve\model\Alliance();
                foreach ($data as $var => $val) {
                    $alliance->$var = $val;
                }
                $this->authorizedAlliances[] = $alliance;
            }
        }
        else
        {
            $allowedCorporations = array();
            $allowedAlliances = array();
            $authGroupController = new \admin\controller\AuthGroup();
            foreach ($this->getAuthGroupsIDs() as $authGroupID)
            {
                foreach ($authGroupController->getCorporationsByAuthGroupID($authGroupID) as $corp) {
                    $allowedCorporations[] = $corp->id;
                }
                foreach ($authGroupController->getAlliancesByAuthGroupID($authGroupID) as $ally) {
                    $allowedAlliances[] = $ally->id;
                }
            }

            foreach ($this->getCorporations() as $id)
            {
                if (in_array($id, $allowedCorporations))
                    $this->authorizedCorporations[] = new \eve\model\Corporation($id);
            }
            foreach ($this->getAlliances() as $id)
            {
                if (in_array($id, $allowedAlliances))
                    $this->authorizedAlliances[] = new \eve\model\Alliance($id);
            }

            \Cache::file()->set($cacheFilename, json_encode(array(  "corporations"	=> $this->authorizedCorporations,
                                                                    "alliances"		=> $this->authorizedAlliances)));
        }
    }

    /**
     * get the users authorized corporations
     * @return \eve\model\Corporation[]
     */
    public function getAuthorizedCorporations()
    {
        \AppRoot::debug("User->getAuthorizedCorporations()");
        if ($this->authorizedCorporations === null)
            $this->fetchAuthorizedCorporationsAlliances();

        return $this->authorizedCorporations;
    }

    /**
     * get the users authorized alliances
     * @return \eve\model\Alliance[]
     */
    public function getAuthorizedAlliances()
    {
        \AppRoot::debug("User->getAuthorizedAlliances()");
        if ($this->authorizedAlliances === null)
            $this->fetchAuthorizedCorporationsAlliances();

        return $this->authorizedAlliances;
    }

    /**
     * Is this user authorized to use vippy?
     * @return boolean
     */
    public function isAuthorized()
    {
        \AppRoot::debug("User($this->displayname)->isAuthorized(): ".(($this->isValid===null)?"null":$this->isValid), true);
        if ($this->isValid === null)
            $this->fetchIsAuthorized();

        return $this->isValid;
    }

    public function fetchIsAuthorized()
    {
        \AppRoot::debug("User($this->displayname)->fetchIsAuthorized()");
        $this->isValid = false;

        if ($this->deleted)
            $this->isValid = false;
        else if (count($this->getAuthorizedCharacters()) > 0)
            $this->isValid = true;
    }

    public function getAllChains()
    {
        $chains = array();
        if ($results = \MySQL::getDB()->getRows("SELECT *
                                                FROM 	mapwormholechains
                                                ORDER BY prio DESC"))
        {
            foreach ($results as $result) {
                $chains[$result["id"]] = $result["name"];
            }
        }
        return $chains;
    }

    /**
     * Get available chains
     * @param bool $fromCache
     * @return \map\model\Map[]
     */
    public function getAvailibleChains($fromCache=true)
    {
        \AppRoot::debug("User->getAvailibleChains($fromCache)");
        if ($this->chains === null || !$fromCache)
        {
            $this->chains = array();
            if (count($this->getAuthorizedCharacters()) == 0) {
                \AppRoot::debug("<span style='color:red;'>No authorized characters found</span>");
                return $this->chains;
            }

            $results = null;
            $cacheFilename = $this->getCacheDirectory() . "chains.json";
            if ($fromCache) {
                if ($cache = \Cache::file()->get($cacheFilename))
                    $results = json_decode($cache, true);
            }

            if ($results == null)
            {
                $characterIDs = array();
                foreach ($this->getAuthorizedCharacters() as $character) {
                    $characterIDs[] = $character->id;
                }

                $queries = [];

                // Corporation
                $queries[] = "select  c.*, c.prio as sort
                              from	mapwormholechains c
                                  inner join mapwormholechains_corporations cc ON cc.chainid = c.id
                                  inner join characters chr ON chr.corpid = cc.corpid
                              where	  c.deleted = 0
                              and     c.authgroupid in (".implode(",",$this->getAuthGroupsIDs()).")
                              and     chr.id IN (".implode(",",$characterIDs).")";
                // Alliance
                $queries[] = "select  c.*, c.prio as sort
                              from	  mapwormholechains c
                                  inner join mapwormholechains_alliances ca ON ca.chainid = c.id
                                  inner join corporations corp ON corp.allianceid = ca.allianceid
                                  inner join characters chr ON chr.corpid = corp.id
                              where	  c.deleted = 0
                              and     c.authgroupid in (".implode(",",$this->getAuthGroupsIDs()).")
                              and     chr.id IN (".implode(",",$characterIDs).")";
                // Accesslist
                $accessListIDs = [];
                foreach ($this->getAccessLists() as $accessList) {
                    $accessListIDs[] = $accessList->id;
                }
                if (count($accessListIDs) > 0) {
                    $queries[] = "select  c.*, c.prio+1000 as sort
                                  from    mapwormholechains c
                                      inner join mapwormholechains_accesslists al on al.chainid = c.id
                                  where   al.accesslistid in (".implode(",",$accessListIDs).")";
                }

                if ($results = \MySQL::getDB()->getRows("select * from (".implode(" union ", $queries).") map group by id order by sort,id")) {
                    \Cache::file()->set($cacheFilename, json_encode($results));
                }
            }

            foreach ($results as $result)
            {
                $chain = new \map\model\Map();
                $chain->load($result);

                if ($chain->directorsOnly && !$this->getIsSysAdmin()) {
                    if (!$this->isAdmin())
                        continue;
                }

                $this->chains[] = $chain;
            }
        }

        \AppRoot::debug("/User->getAvailibleChains($fromCache)");
        return $this->chains;
    }

    public function getAvailibleChainIDs()
    {
        $ids = array();
        foreach ($this->getAvailibleChains() as $chain) {
            $ids[] = $chain->id;
        }
        return $ids;
    }

    public function getCurrentAuthGroupID()
    {
        return $this->getCurrentAuthGroup()->id;
    }

    /**
     * Get authgroup
     * @return \admin\model\AuthGroup
     */
    public function getCurrentAuthGroup()
    {
        if ($this->currentAuthGroup === null) {
            foreach ($this->getAuthGroups() as $group) {
                $this->currentAuthGroup = $group;
                break;
            }
        }
        return $this->currentAuthGroup;
    }

    /**
     * Get auth-group ids
     * @param bool $fromCache
     * @return array
     */
    public function getAuthGroupsIDs($fromCache=true)
    {
        \AppRoot::debug("User->getAuthGroupsIDs()");
        if (!is_array($this->authGroupIDs) || count($this->authGroupIDs) == 0)
        {
            $cacheFilename = $this->getCacheDirectory()."authgroups.json";
            if ($fromCache && $cache = \Cache::file()->get($cacheFilename))
                $this->authGroupIDs = json_decode($cache,true);
            else
            {
                $this->authGroupIDs = [];
                if ($results = \MySQL::getDB()->getRows("
                            select  *
                            from    user_auth_groups
                            where   id in (
                                select	if (uc.authgroupid is not null, uc.authgroupid, ua.authgroupid) AS authgroupid
                                from    users u
                                    inner join characters c on c.userid = u.id
                                    inner join crest_token t on t.tokentype = 'character' and t.tokenid = c.id
                                    inner join corporations corp on corp.id = c.corpid
                                    left join user_auth_groups_corporations uc on uc.corporationid = corp.id
                                    left join user_auth_groups_alliances ua on ua.allianceid = corp.allianceid
                                where	u.id = ?
                                and     (uc.authgroupid is not null or ua.authgroupid is not null))"
                        , array($this->id)))
                {
                    foreach ($results as $result)
                    {
                        $authgroup = new \admin\model\AuthGroup();
                        $authgroup->load($result);

                        // Check if manual authorization is required
                        $allowed = false;
                        if ($authgroup->getConfig("access_control") == "manual") {
                            if ($this->isAdmin())
                                $allowed = true;
                            else {
                                foreach ($authgroup->getGrantedUsers() as $user) {
                                    if ($user->id == $this->id) {
                                        $allowed = true;
                                        break;
                                    }
                                }
                            }
                        } else
                            $allowed = true;

                        if ($allowed) {
                            $this->authGroups[] = $authgroup;
                            $this->authGroupIDs[] = $authgroup->id;
                        }
                    }
                }
                \Cache::file()->set($cacheFilename, json_encode($this->authGroupIDs));
            }
        }

        return $this->authGroupIDs;
    }

    public function resetAuthGroups()
    {
        $this->authGroups = null;
    }

    /**
     * Get authgroups
     * @return \admin\model\AuthGroup[]
     */
    public function getAuthGroups()
    {
        if ($this->authGroups === null)
        {
            \AppRoot::doCliOutput("[$this->id] ".$this->displayname." ->getAuthGroups()");
            $this->authGroups = array();
            foreach ($this->getAuthGroupsIDs() as $id)
            {
                // dubbele?
                $exists = false;
                foreach ($this->authGroups as $group) {
                    if ($group->id == $id)
                        $exists = true;
                }
                if (!$exists) {
                    $group = new \admin\model\AuthGroup($id);
                    $this->authGroups[] = $group;
                    \AppRoot::debug(" * ".$group->name);
                }
            }
        }
        return $this->authGroups;
    }

    /**
     * Get authgroups that this user can admin
     * @return \admin\model\AuthGroup[]
     */
    public function getAuthGroupsAdmins()
    {
        $authGroups = array();
        foreach ($this->getAuthGroups() as $group)
        {
            // Heb ik directors in deze groep?
            foreach ($group->getAllowedCorporations() as $corp)
            {
                if (\User::getUSER()->isAdmin($corp->id))
                {
                    $authGroups[] = $group;
                    continue;
                }
            }
        }
        return $authGroups;
    }

    /**
     * Get access lists
     * @return \admin\model\AccessList[]
     */
    public function getAccessLists()
    {
        \AppRoot::debug("User->getAccessLists()");
        if ($this->_accessLists === null)
        {
            $this->_accessLists = [];
            if ($results = \MySQL::getDB()->getRows("select a.*
                                                     from   user_accesslist a
                                                        inner join user_accesslist_user u on u.userid = a.id
                                                     where  u.userid = ?
                                                  union
                                                     select a.*
                                                     from   user_accesslist a
                                                        inner join user_accesslist_characters ac on ac.accesslistid = a.id
                                                        inner join characters c on c.id = ac.characterid
                                                     where  c.userid = ?
                                                  union
                                                     select a.*
                                                     from   user_accesslist a
                                                        inner join user_accesslist_corporation ac on ac.accesslistid = a.id
                                                        inner join characters c on c.id = ac.corporationid
                                                    where  c.userid = ?
                                                  union
                                                     select a.*
                                                     from   user_accesslist a
                                                        inner join user_accesslist_alliance aa on aa.accesslistid = a.id
                                                        inner join corporations cc on cc.allianceid = aa.allianceid
                                                        inner join characters c on c.corpid = cc.id
                                                    where  c.userid = ?
                                                  union
                                                    select  a.*
                                                    from    user_accesslist a
                                                    where   a.ownerid = ?
                                                group by id
                                                order by title, id"
                                , [$this->id, $this->id, $this->id, $this->id, $this->id]))
            {
                foreach ($results as $result)
                {
                    $list = new \admin\model\AccessList();
                    $list->load($result);
                    $this->_accessLists[] = $list;
                }
            }
        }

        return $this->_accessLists;
    }

    /**
     * Get access lists that this user can admin
     * @return \admin\model\AccessList[]
     */
    public function getAdminAccessLiss()
    {
        $lists = [];
        foreach ($this->getAccessLists() as $list) {
            if ($list->canAdmin($this->id))
                $lists[] = $list;
        }
        return $lists;
    }

    public function getVisibleUserIDs()
    {
        \AppRoot::debug("User->getVisibleUserIDs()");
        if ($this->visibleUserIDs == null)
        {
            // Cache stuff
            if ($this->loggedIn() && \Tools::REQUEST("ajax")) {
                if (isset($_SESSION["vippy_visible_userids"])) {
                    $this->visibleUserIDs = $_SESSION["vippy_visible_userids"];
                    return $this->visibleUserIDs;
                }
            }

            $this->visibleUserIDs = array();
            if ($results = \MySQL::getDB()->getRows("SELECT	u.id
                                                    FROM	users u
                                                        INNER JOIN characters c ON c.userid = u.id
                                                        INNER JOIN corporations corp ON corp.id = c.corpid
                                                        LEFT JOIN user_auth_groups_corporations uc ON uc.corporationid = corp.id
                                                        LEFT JOIN user_auth_groups_alliances ua ON ua.allianceid = corp.allianceid
                                                    WHERE	(uc.authgroupid IN (".implode(",", $this->getAuthGroupsIDs()).")
                                                    OR		ua.authgroupid IN (".implode(",", $this->getAuthGroupsIDs())."))
                                                    GROUP BY u.id"))
            {
                foreach ($results as $result) {
                    $this->visibleUserIDs[] = $result["id"];
                }
            }

            // Cache stuff
            if ($this->loggedIn())
                $_SESSION["vippy_visible_userids"] = $this->visibleUserIDs;
        }

        return $this->visibleUserIDs;
    }

    /**
     * Get last log entry
     * @return array|boolean false
     */
    public function getLastLogin()
    {
        if ($result = \MySQL::getDB()->getRow("	SELECT 	*
                                                FROM 	user_log
                                                WHERE 	userid = ?
                                                ORDER BY logdate DESC LIMIT 1"
                                    , array($this->id)))
            return $result;
        else
            return false;
    }



    /**
     * Get user logs
     * @return \users\model\Log[]
     */
    public function getUserLog()
    {
        if ($this->logs === null)
            $this->logs = \users\model\Log::getLogByUser($this->id);

        return $this->logs;
    }

    /**
     * Was this user active in a certain period?
     * @param string $sdate datetime(Y-m-d) default = this month
     * @param string $edate datetime(Y-m-d) default = this month
     * @return boolean
     */
    public function getIsActive($sdate=null, $edate=null)
    {
        $sdate = ($sdate != null) ? date("Y-m-d", strtotime($sdate)) : date("Y-m-d", mktime(0,0,0,date("m"), date("d")-30, date("Y")));
        $edate = ($edate != null) ? date("Y-m-d", strtotime($edate)) : date("Y-m-d", mktime(0,0,0,date("m"), date("d"),date("Y")));

        // Laatste login log
        if ($result = \MySQL::getDB()->getRow("select *
                                               from   user_log
                                               where  userid = ? and what = 'login'
                                               and    logdate between ? and ?
                                               order by logdate desc limit 1"
                                , [$this->id, $sdate, $edate]))
        {
            $log = new \users\model\Log();
            $log->load($result);
            return true;
        }

        return false;
    }

    /**
     * Get nr hours online
     * @param null $sdate
     * @param null $edate
     * @return float
     */
    public function getHoursOnline($sdate=null, $edate=null)
    {
        $nrSecondsOnline = [];
        $sdate = ($sdate != null) ? date("Y-m-d", strtotime($sdate)) : date("Y-m-d", mktime(0,0,0,date("m"), 1,date("Y")));
        $edate = ($edate != null) ? date("Y-m-d", strtotime($edate)) : date("Y-m-d", mktime(0,0,0,date("m")+1, 0,date("Y")));

        // Zoek naar ingame log entries.
        foreach (\users\model\Log::getLogByUserOnDate($this->id, $sdate, $edate, "ingame") as $log)
        {
            if ($log->pilotID)
            {
                if (!isset($nrSecondsOnline[date("Y-m-d", strtotime($log->logDate))]))
                    $nrSecondsOnline[date("Y-m-d", strtotime($log->logDate))] = 0;

                $seconds = (strtotime($log->lastDate)-strtotime($log->logDate));
                if ($nrSecondsOnline[date("Y-m-d", strtotime($log->logDate))] < $seconds)
                    $nrSecondsOnline[date("Y-m-d", strtotime($log->logDate))] = $seconds;
            }
        }

        $total = 0;
        foreach ($nrSecondsOnline as $day => $seconds) {
            $total += $seconds;
        }

        return round(($total/60)/60,2);
    }

    /**
     * Get active notifications
     * @return \users\model\Notification[]
     */
    public function getActiveNotifications()
    {
        if (\Tools::POST("readNotification"))
        {
            $note = new \users\model\Notification(\Tools::POST("readNotification"));
            if ($note->userID == $this->id) {
                $note->readDate = date("Y-m-d H:i:s");
                $note->store();
            }
            \AppRoot::refresh();
        }

        if ($this->notifications === null)
            $this->notifications = \users\model\Notification::getNotificationsByUser($this->id);

        return $this->notifications;
    }





    /**
     * Get valid users by corp
     * @param integer $corporationID
     * @return \users\model\User[]
     */
    public static function getUsersByCorporation($corporationID)
    {
        $users = array();
        if ($results = \MySQL::getDB()->getRows("SELECT	u.*
                                                FROM	users u
                                                    INNER JOIN characters c ON c.userid = u.id
                                                    INNER JOIN crest_token t ON t.tokentype = 'character' AND t.tokenid = c.id
                                                WHERE	c.corpid = ?
                                                GROUP BY u.id
                                                ORDER BY u.displayname ASC"
                                    , array($corporationID)))
        {
            foreach ($results as $result)
            {
                $user = new \users\model\User();
                $user->load($result);
                $users[] = $user;
            }
        }

        return $users;
    }

    /**
     * Get user by key
     * @return \users\model\User|null
     */
    public static function getUserByKey($key)
    {
        if ($result = \MySQL::getDB()->getRow("SELECT * FROM users WHERE loginkey = ? AND deleted = 0", array($key)))
        {
            $user = new \users\model\User();
            $user->load($result);
            return $user;
        }

        return null;
    }

    public static function getUserByToon($characterid)
    {
        if ($result = \MySQL::getDB()->getRow("SELECT userid FROM characters WHERE id = ?", array($characterid)))
        {
            \AppRoot::debug("An userid is found for this character");
            $user = new \users\model\User($result["userid"]);
            return $user;
        }
        return null;

    }

    /**
     * Find user by username
     * @param $username
     * @return \users\model\User|null
     */
    public static function getUserByUsername($username)
    {
        if ($result = \MySQL::getDB()->getRow("SELECT * FROM users WHERE username = ?", array($username)))
        {
            $user = new \users\model\User();
            $user->load($result);
            return $user;
        }
        return null;
    }

    public static function loginByKey($key)
    {
        $loggedIn = false;

        $user = \users\model\User::getUserByKey($key);
        if ($user !== null) {
            $user->setLoginStatus();
            $loggedIn = true;
        }

        if ($user->id == 0)
            return false;

        if ($loggedIn)
            $user->addLog("login");

        return $loggedIn;
    }
}