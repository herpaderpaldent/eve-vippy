<?php
namespace map\controller;

class LocationTracker
{
    /**
     * Set character location
     * @param \esi\model\Character $character
     * @param $locationID
     * @param $shipTypeID
     * @return bool
     */
    function setCharacterLocation(\esi\model\Character $character, $locationID, $shipTypeID=null)
    {
        \AppRoot::doCliOutput("LocationTracker->setCharacterLocation($character->id, $locationID, $shipTypeID)");

        // Vorige locatie?
        $previousLocationID = null;
        $location = $character->getLocation();
        if ($location)
            $previousLocationID = $location->solarsystemID;

        $character->setLocation($locationID, $shipTypeID, true);
        \AppRoot::doCliOutput("Previous Location: ".$previousLocationID);
        \AppRoot::doCliOutput("Current Location: ".$locationID);

        if ($locationID && $previousLocationID)
        {
            if (!is_numeric($previousLocationID))
                return false;

            // We jumpen naar een ander systeem!
            if ($previousLocationID != $locationID) {
                // Check alle maps van deze toon
                foreach (\map\model\Map::findByCharacter($character) as $map) {
                    \AppRoot::doCliOutput("map: ".$map->name);

                    // In het geval van een pod geen nieuw wormhole toevoegen.
                    if ($shipTypeID && in_array($shipTypeID, [0, 670, 33328])) {
                        \AppRoot::debug("Pod.. Abort!");
                        continue;
                    }

                    /** @var \map\model\Wormhole[] $addedWormholes */
                    $addedWormholes = [];
                    $addNewWormhole = true;
                    $wormholeFrom = null;
                    $wormholeTo = null;

                    // Staan beide systemen al op de map?
                    if ($results = \MySQL::getDB()->getRows("SELECT * FROM mapwormholes WHERE chainid = ? AND solarsystemid IN (" . $previousLocationID . "," . $locationID . ")", [$map->id])) {
                        foreach ($results as $result) {
                            $wh = new \map\model\Wormhole();
                            $wh->load($result);
                            if ($wh->solarSystemID == $previousLocationID)
                                $wormholeFrom = $wh;
                            else
                                $wormholeTo = $wh;
                        }
                    }

                    // Beide systemen zijn al bekend.
                    if ($wormholeTo != null && $wormholeFrom != null) {
                        \AppRoot::debug("Both system known. Do not add!");
                        $addNewWormhole = false;
                    }
                    // Beide systemen zijn niet bekend.
                    if ($wormholeTo == null && $wormholeFrom == null) {
                        \AppRoot::debug("Neither systems known. Do not add!");
                        $addNewWormhole = false;
                    }

                    $fromSystem = new \map\model\SolarSystem($previousLocationID);
                    $toSystem = new \map\model\SolarSystem($locationID);

                    // kspace-kspace systems
                    if ($addNewWormhole) {
                        if (!$fromSystem->isWSpace() && !$toSystem->isWSpace()) {
                            // Iets hoger dan 1 door lag in de CREST tracker
                            if ($fromSystem->getNrJumpsTo($toSystem->id) <= 3)
                                $addNewWormhole = false;
                        }
                    }

                    // Magic!
                    if ($addNewWormhole) {
                        \AppRoot::debug("Add new wormhole!");
                        $controller = new \map\controller\Wormhole();
                        $addedWH = $controller->addWormhole($map, $previousLocationID, $locationID);
                        if ($addedWH)
                            $addedWormholes[] = $addedWH;
                    }

                    $connection = \map\model\Connection::getConnectionByLocations($previousLocationID, $locationID, $map->id);
                    if ($connection) {
                        // Systems zijn connected. Lekker makkelijk, registreer jump.
                        $connection->addJump($shipTypeID, $character->id, false);
                    } else {
                        // Systems niet connected. Zoek alle tussenliggende holes, en registreer daar ook de jump!
                        $route = new \map\model\Route();
                        $route->setMap($map);
                        $route->setFromSystem($fromSystem);
                        $route->setToSystem($toSystem);

                        $connections = $route->getConnections();
                        if ($connections) {
                            foreach ($connections as $connection) {
                                $connection->addJump($shipTypeID, $character->id, false);
                            }
                        }
                    }

                    if (count($addedWormholes) > 0) {
                        // Toevoegen aan stats.
                        $wormhole = $addedWormholes[0];
                        if ($character->getUser() || \User::getUSER()) {
                            $stat = new \stats\model\Whmap();
                            $stat->userID = ($character->getUser()) ? $character->getUser()->id : \User::getUSER()->id;
                            $stat->corpID = $character->corporationID;
                            $stat->pilotID = $character->id;
                            $stat->systemID = $wormhole->solarSystemID;
                            $stat->authGroupID = $wormhole->getChain()->authgroupID;
                            $stat->mapdate = date("Y-m-d H:i:s");
                            $stat->store();
                        }
                    }

                    // Trigger map update
                    $map->setMapUpdateDate(false);
                }
            }
        }

        // Log entry
        if ($character->getUser()) {
            $session = "crest-".$character->getUser()->id."-".date("Ymd");
            $character->getUser()->addLog("ingame", $character->id, null, $character->id, $session);
            $cache = \Cache::getCache("file")->get("userlog/".$session);
            if ($cache) {
                $cache = json_decode($cache);
            } else {
                $cache = new \stdClass();
                $cache->userid = $character->getUser()->id;
                $cache->pilotid = $character->id;
                $cache->what = "ingame";
                $cache->whatid = $character->id;
                $cache->startdate = date("Y-m-d H:i:s");
            }
            $cache->lastdate = date("Y-m-d H:i:s");
            \Cache::getCache("file")->set("userlog/".$session, $cache);
        }

        return true;
    }

    function getCharacterLocationsByAuthGroup($authGroupID, $timelimit=300)
    {
        $characters = [];
        $cacheDirectory = \Cache::file()->getDirectory()."locations/";
        foreach (\Tools::getFilesFromDirectory($cacheDirectory.$authGroupID, false, false) as $file) {
            $data = json_decode(file_get_contents($file));
            if ($timelimit && isset($data->lastdate) && $data->lastdate < strtotime("now")-$timelimit) {
                continue;
            }
            if (isset($data->characterName)) {
                $characters[$data->characterName] = [
                    "id" 	=> $data->characterID,
                    "name" 	=> $data->characterName,
                    "system" => (isset($data->solarsystemID)) ? ["id" => $data->solarsystemID, "name" => (isset($data->solarsystemName))?$data->solarsystemName:null] : null,
                    "ship" => (isset($data->shiptypeID)) ? ["id" => $data->shiptypeID, "name" => (isset($data->shiptypeName))?$data->shiptypeName:null] : null,
                    "isme" => (\User::getUSER()->id == $data->userID)?1:0,
                    "time" => date("Y-m-d H:i:s", $data->lastdate)
                ];
            }
        }
        ksort($characters);
        return $characters;
    }
}