<?php
namespace map\controller;

class LocationTracker
{
    /**
     * Set character location
     * @param $authGroupID
     * @param $characterID
     * @param $locationID
     * @param $shipTypeID
     * @return bool
     */
    function setCharacterLocation($authGroupID, $characterID, $locationID, $shipTypeID=0)
    {
        \AppRoot::doCliOutput("setCharacterLocation($authGroupID, $characterID, $locationID, $shipTypeID)");
        if (!is_numeric($locationID))
            return false;

        $previousLocationID = null;
        $previousShipTypeID = null;

        if ($previousLocation = \MySQL::getDB()->getRow("select * from map_character_locations where characterid = ? and lastdate > ?"
                                                , [$characterID, date("Y-m-d H:i:s", strtotime("now")-60)]))
        {
            $previousLocationID = $previousLocation["solarsystemid"];
            $previousShipTypeID = $previousLocation["shiptypeid"];
        }
        if (!$shipTypeID)
            $shipTypeID = $previousShipTypeID;

        // Huidige locatie opslaan.
        \MySQL::getDB()->updateinsert("map_character_locations", [
            "characterid" => $characterID,
            "solarsystemid" => $locationID,
            "shiptypeid" => $shipTypeID,
            "authgroupid" => $authGroupID,
            "lastdate" => date("Y-m-d H:i:s")
        ],[
            "characterid" => $characterID
        ]);

        // Update character last-online
        \MySQL::getDB()->update("characters", ["lastonline" => date("Y-m-d H:i:s")], ["id" => $characterID]);



        if ($previousLocationID)
        {
            if (!is_numeric($previousLocationID))
                return false;

            // We jumpen naar een ander systeem!
            if ($previousLocationID != $locationID)
            {
                // Map update
                \MySQL::getDB()->update("mapwormholechains", ["lastmapupdatedate" => date("Y-m-d H:i:s")], ["authgroupid" => $authGroupID]);

                // Pods tellen niet mee.
                if (!in_array($shipTypeID, [0, 670, 33328]))
                {
                    /** @var \map\model\Wormhole[] $addedWormholes */
                    $addedWormholes = [];

                    // Check alle maps van deze authgroup
                    foreach (\map\model\Map::findAll(["authgroupid" => $authGroupID]) as $map)
                    {
                        $addNewWormhole = true;
                        $wormholeFrom = null;
                        $wormholeTo = null;

                        // Staan beide systemen al op de map?
                        if ($results = \MySQL::getDB()->getRows("select * from mapwormholes where chainid = ?
                                                                 and solarsystemid in (".$previousLocationID.",".$locationID.")"
                                                        , [$map->id]))
                        {
                            foreach ($results as $result)
                            {
                                $wh = new \map\model\Wormhole();
                                $wh->load($result);

                                if ($wh->solarSystemID == $previousLocationID)
                                    $wormholeFrom = $wh;
                                else
                                    $wormholeTo = $wh;
                            }
                        }

                        // Beide systemen zijn al bekend.
                        if ($wormholeTo != null && $wormholeFrom != null)
                            $addNewWormhole = false;
                        // Beide systemen zijn niet bekend.
                        if ($wormholeTo == null && $wormholeFrom == null)
                            $addNewWormhole = false;

                        $fromSystem = new \map\model\SolarSystem($previousLocationID);
                        $toSystem = new \map\model\SolarSystem($locationID);

                        // kspace-kspace systems
                        if ($addNewWormhole) {
                            if (!$fromSystem->isWSpace() && !$toSystem->isWSpace()) {
                                if ($fromSystem->getNrJumpsTo($toSystem->id) <= 3) // iets hoger dan 1 door lag in de CREST tracker
                                    $addNewWormhole = false;
                            }
                        }

                        // Magic!
                        if ($addNewWormhole) {
                            $controller = new \map\controller\Wormhole();
                            $addedWH = $controller->addWormhole($map, $previousLocationID, $locationID);
                            if ($addedWH)
                                $addedWormholes[] = $addedWH;
                        }

                        $connection = \map\model\Connection::getConnectionByLocations($previousLocationID, $locationID, $map->id);
                        if ($connection)
                        {
                            // Systems zijn connected. Lekker makkelijk, registreer jump.
                            $connection->addJump($shipTypeID, $characterID, false);
                        }
                        else
                        {
                            // Systems niet connected. Zoek alle tussenliggende holes, en registreer daar ook de jump!
                            $route = new \map\model\Route();
                            $route->setMap($map);
                            $route->setFromSystem($fromSystem);
                            $route->setToSystem($toSystem);

                            $connections = $route->getConnections();
                            if ($connections) {
                                foreach ($connections as $connection) {
                                    $connection->addJump($shipTypeID, $characterID, false);
                                }
                            }
                        }
                    }

                    foreach ($addedWormholes as $wormhole)
                    {
                        // Toevoegen aan stats.
                        $char = \eve\model\Character::findByID($characterID);
                        if ($char->getUser() || \User::getUSER())
                        {
                            $stat = new \stats\model\Whmap();
                            $stat->userID = ($char->getUser())?$char->getUser()->id:\User::getUSER()->id;
                            $stat->corpID = $char->corporationID;
                            $stat->pilotID = $char->id;
                            $stat->systemID = $wormhole->solarSystemID;
                            $stat->authGroupID = $wormhole->getChain()->authgroupID;
                            $stat->mapdate = date("Y-m-d H:i:s");
                            $stat->store();
                        }
                        // Hoeft maar 1x
                        break;
                    }
                }
            }
        }

        return false;
    }
}