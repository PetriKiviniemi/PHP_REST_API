<?php

// Parse the request URI
$request = $_SERVER['REQUEST_URI'];
$scriptName = $_SERVER['SCRIPT_NAME'];
$path = str_replace($scriptName, '', $request);
$path = trim($path, '/');
$segments = explode('/', $path);
$method = $_SERVER['REQUEST_METHOD'];
$request_body = file_get_contents('php://input');

$base_path = '/~kiviniemip35/player_service.php';

function send404($message = "") {
    http_response_code(404);
    echo json_encode(['error' => 'Invalid request: ' . $message]);
}

// NOTE:: This function should not have to exist
// It is a problem with json_decode + encode combo
function parse_extra_quotes_and_backslashes($value)
{
    if ((strpos($value, '"') === 0 && strrpos($value, '"') === strlen($value) - 1) ||
        (strpos($value, "'") === 0 && strrpos($value, "'") === strlen($value) - 1)) {
        $value = trim($value, "\"'");
        $value = stripslashes($value);
    }
    return $value;
}

function prettyPrintDOMNode(DOMNode $node) {
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->formatOutput = true;

    // Import the node into the new document
    $importedNode = $dom->importNode($node, true);
    $dom->appendChild($importedNode);

    // Save and print the formatted XML
    echo $dom->saveXML();
}

function replaceNaturalDisasterJsonKeyValuePairs($original, $patch)
{
    // Patch it with the fields from user input
    foreach ($patch as $key => $value) {
        if (!isset($original->{$key}) && $key != "disasterDebuffs") {
            print_r("INVALID KEY\n");
            print_r($key);
            return "INVALID KEYS IN PATCH";
        }

        // Special case for 'disasterDebuffs' array
        if ($key === 'disasterDebuffs') {
            foreach ($value as $newDebuff) {
                $found = false;

                // Iterate the existing debuffs, try to find one
                // with same UUID, if found, patch its data

                foreach ($original->disasterDebuffs as $index => $existingDebuff) {
                    if ($newDebuff->uuid === $existingDebuff->uuid) {
                        // Update existing debuff
                        $result = replaceNaturalDisasterJsonKeyValuePairs($original->disasterDebuffs[$index], $newDebuff);
                        if ($result === "INVALID KEYS IN PATCH") {
                            return "INVALID KEYS IN PATCH";
                        }
                        $found = true;
                        break;
                    }
                }

                // Append new debuff if not found
                if (!$found) {
                    $original->disasterDebuffs[] = $newDebuff;
                }
            }
        } elseif (is_object($value) && is_object($original->{$key}) ||
            is_array($value) && is_array($original->{$key})) {
            // Call this recursively if we are dealing with objects or arrays
            $result = replaceNaturalDisasterJsonKeyValuePairs($original->{$key}, $value);
            if ($result === "INVALID KEYS IN PATCH") {
                return "INVALID KEYS IN PATCH";
            }
        } else {
            // Update the original object property with patch value
            $original->{$key} = $value;
        }
    }

    return 0;
}




function naturalDisasterStdClassToDOMNode($data, $xml) {
    try
    {
        $naturalDisasterElement = $xml->createElement('naturaldisaster');
        $naturalDisasterElement->setAttribute('id', $data->id);
        $naturalDisasterElement->setAttribute('name', $data->name);

        $durationElement = $xml->createElement('duration', $data->duration);
        $naturalDisasterElement->appendChild($durationElement);

        $dateTime = new DateTime();
        $dateTime->setTimestamp($data->timeoccured);
        $dateTime->setTimezone(new DateTimeZone('UTC'));
        $formattedDateTime = $dateTime->format('Y-m-d\TH:i:s.u'); // Format to 'Y-m-d\TH:i:s.u'
        $timeOccurredElement = $xml->createElement('timeoccurred', $formattedDateTime);
        $naturalDisasterElement->appendChild($timeOccurredElement);

        foreach ($data->disasterDebuffs as $disasterDebuff) {
            $debuffElement = $xml->createElement('debuff');

            $trimmed_uuid = parse_extra_quotes_and_backslashes($disasterDebuff->uuid);
            $uuidElement = $xml->createElement('uuid', $trimmed_uuid);
            $debuffElement->appendChild($uuidElement);


            $trimmed_description = parse_extra_quotes_and_backslashes($disasterDebuff->description);
            $descriptionElement = $xml->createElement('description', $trimmed_description);
            $debuffElement->appendChild($descriptionElement);

            $effectsElement = $xml->createElement('effects');

            foreach ($disasterDebuff->effects as $effect) {
                $effectElement = $xml->createElement('effect', $effect);
                $effectsElement->appendChild($effectElement);
            }

            $debuffElement->appendChild($effectsElement);
            $naturalDisasterElement->appendChild($debuffElement);
        }

        return $naturalDisasterElement;
    } 
    catch (Exception $e)
    {
        print_r("Error parsing NaturalDisaster StdClass: {$e}");
        return null;
    }
}

function NaturalDisasterDOMToStdClass ($NDDom)
{
    try{

        $updatedNaturalDisasterStdClass = new StdClass;
        $updatedNaturalDisasterStdClass->id = $NDDom->getAttribute('id');
        $updatedNaturalDisasterStdClass->name = $NDDom->getAttribute('name');
        $updatedNaturalDisasterStdClass->duration = $NDDom->getElementsByTagName('duration')->item(0)->nodeValue;
        $updatedNaturalDisasterStdClass->timeoccurred= $NDDom->getElementsByTagName('timeoccurred')->item(0)->nodeValue;
        $debuffObjects = $NDDom->getElementsByTagName("debuff");
        $updatedNaturalDisasterStdClass->disasterDebuffs = [];

        foreach($debuffObjects as $debuff)
        {
            $debuffObj = new StdClass;


            $trimmed_uuid = parse_extra_quotes_and_backslashes($debuff->getElementsByTagName("uuid")->item(0)->nodeValue);
            $debuffObj->uuid = $trimmed_uuid;

            $trimmed_description = parse_extra_quotes_and_backslashes($debuff->getElementsByTagName("description")->item(0)->nodeValue);
            $debuffObj->description = $trimmed_description;

            $effectsElement = $debuff->getElementsByTagName("effects")->item(0);
            $debuffObj->effects = [];

            if ($effectsElement) {
                // Get all 'effect' nodes within the 'effects' element
                $effectNodes = $effectsElement->getElementsByTagName("effect");

                foreach ($effectNodes as $effectNode) {
                    // Add the effect object to the effects array
                    $trimmed_effect = parse_extra_quotes_and_backslashes($effectNode->nodeValue);
                    $debuffObj->effects[] = $trimmed_effect;
                }
            }
            $updatedNaturalDisasterStdClass->disasterDebuffs[] = $debuffObj;
        }

        return $updatedNaturalDisasterStdClass;
    }
    catch (Exception $e) {
        print_r('Error: ' . $e->getMessage());
    }
}


class PlayerService {
    private $xmlFile = 'output1.xml';

    private function loadXML() {
        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->load($this->xmlFile);
        return $xml;
    }

    private function saveXML($xml) {
        $xml->save($this->xmlFile);
        return $xml;
    }

    public function GetPlayerStats($name) {
        $xml = $this->loadXML();
        $xpath = new DOMXPath($xml);

        //Using xpath to retrieve 
        $query = sprintf("//player/stats[name='%s']", $name);
        $playerStats = $xpath->query($query)->item(0);

        if ($playerStats) {
            $doc = new DOMDocument();
            $doc->appendChild($doc->importNode($playerStats, true));

            $playerStatsString = $doc->saveXML();

            return $playerStatsString;
        } else {
            return null;
        }
    }

    public function GetNaturalDisaster($playerId, $farmingBotId, $naturalDisasterId)
    {
        $xml = $this->loadXML();
        $xpath = new DOMXPath($xml);

        $query = sprintf("//player[@id='%d']//farmingbot[@id='%d']//naturaldisaster[@id='%d']", $playerId, $farmingBotId, $naturalDisasterId);
        $naturalDisaster = $xpath->query($query)->item(0);

        if($naturalDisaster)
            return NaturalDisasterDOMToStdClass($naturalDisaster);
        return -1;
    }

    /*
    PARAMS:
    $playerId: int
    $farmingBotId: int
    $naturalDisasterData: object
        - id: int
        - name: string
        - duration: float
        - timeoccurred: timestamp (unix)
        - arrayOfDebuffs: array of debuff objects
            - debuff: object
                -   uuid: string
                -   description: string
                -   effects: array
                    -   effect: string
    */
    public function CreateNaturalDisaster($playerId, $farmingBotId, $naturalDisasterData)
    {
        // Load the xml
        $xml = $this->loadXML();
        $xpath = new DOMXPath($xml);

        // Check if the natural disaster with that id already exists
        $query = sprintf("//player[@id='%d']//farmingbot[@id='%d']//naturaldisaster[@id='%d']", $playerId, $farmingBotId, $naturalDisasterData->id);
        $createdNaturalDisasterId = $xpath->query($query)->item(0);

        if($createdNaturalDisasterId)
        {
            return -1;
        }

        //Find the farmingbot of where we will be placing the natural disaster
        $farmingBotQuery = sprintf("//player[@id='%d']/farmingbots/farmingbot[@id='%d']/naturaldisasters", $playerId, $farmingBotId);
        $farmingBotNaturalDisasters = $xpath->query($farmingBotQuery)->item(0);

        $naturalDisasterDOMNode = naturalDisasterStdClassToDOMNode($naturalDisasterData, $xml);

        if($farmingBotNaturalDisasters && $naturalDisasterDOMNode)
        {
            $farmingBotNaturalDisasters->appendChild($naturalDisasterDOMNode);
            $xml->save($this->xmlFile);

            $xpath = new DOMXPath($xml);
            // Check if the natural disaster was created correctly
            $query = sprintf("//player[@id='%d']//farmingbot[@id='%d']//naturaldisaster[@id='%d']", $playerId, $farmingBotId, $naturalDisasterData->id);
            $createdNaturalDisasterId = $xpath->query($query)->item(0);
            return $createdNaturalDisasterId->getAttribute("id");
        }

        return -1;
    }

    //This function assumes that we do not have partial but instead the full natural disaster data
    public function UpdateNaturalDisaster($playerId, $farmingBotId, $naturalDisasterData)
    {
        // Load the xml
        $xml = $this->loadXML();
        $xpath = new DOMXPath($xml);

        // Check if the natural disaster with that id already exists
        $query = sprintf("//player[@id='%d']//farmingbot[@id='%d']//naturaldisaster[@id='%d']", $playerId, $farmingBotId, $naturalDisasterData->id);
        $originalNaturalDisaster = $xpath->query($query)->item(0);

        if($originalNaturalDisaster)
        {
            //Lets update the natural disaster
            $updatedNaturalDisasterFromParams = naturalDisasterStdClassToDOMNode($naturalDisasterData, $xml);

            $originalNaturalDisaster->parentNode->replaceChild($updatedNaturalDisasterFromParams, $originalNaturalDisaster);
            
            $xml->save($this->xmlFile);

            //Check that it got updated correctly
            $xpath = new DOMXPath($xml);
            // Check if the natural disaster was created correctly
            $query = sprintf("//player[@id='%d']//farmingbot[@id='%d']//naturaldisaster[@id='%d']", $playerId, $farmingBotId, $naturalDisasterData->id);
            $updatedNaturalDisasterFromDOM = $xpath->query($query)->item(0);

            if($updatedNaturalDisasterFromDOM == $updatedNaturalDisasterFromParams)
                return NaturalDisasterDOMToStdClass($updatedNaturalDisasterFromDOM);
        }

        return -1;
    }

    public function DeleteNaturalDisastersFromTimestamp($playerId, $farmingBotId, $timestamp)
    {
        // Load the xml
        $xml = $this->loadXML();
        $xpath = new DOMXPath($xml);

        // Check if the natural disaster with that id already exists
        $query = sprintf(
            "//player[@id='%d']//farmingbot[@id='%d']/naturaldisasters//naturaldisaster",
            $playerId,
            $farmingBotId,
        );

        $naturalDisasters = $xpath->query($query);
        $deletedDisasters = [];

        foreach ($naturalDisasters as $ND)
        {
            $NDtimestamp = $ND->getElementsByTagName("timeoccurred")->item(0)->nodeValue;
            if($NDtimestamp >= $timestamp)
            {
                // Store the ID before deleting
                $NDId = $ND->getAttribute("id");
                $parent = $ND->parentNode;
                $parent->removeChild($ND);

                $xml->save($this->xmlFile);
                //Check that it was deleted
                $xpath = new DOMXPath($xml);
                $query = sprintf("//player[@id='%d']//farmingbot[@id='%d']//naturaldisaster[@id='%d']", $playerId, $farmingBotId, $NDId);
                $deletedNaturalDisasterFromDOM = $xpath->query($query)->item(0);

                if(!$deletedNaturalDisasterFromDOM)
                {
                    array_push($deletedDisasters, $NDId);
                }
            }
        }

        return $deletedDisasters;
    }

    public function PatchNaturalDisaster($playerId, $farmingBotId,$naturalDisasterId, $patchData)
    {
        $xml = $this->loadXML();
        $xpath = new DOMXPath($xml);

        $patchDataStdClass = json_decode($patchData);
        if(!$patchDataStdClass)
            return -1;

        $query = sprintf("//player[@id='%d']//farmingbot[@id='%d']//naturaldisaster[@id='%d']",
                        $playerId,
                        $farmingBotId,
                        $naturalDisasterId
                    );
        $naturalDisaster = $xpath->query($query)->item(0);

        if($naturalDisaster)
        {
            $naturalDisasterStdClass = NaturalDisasterDOMToStdClass($naturalDisaster);


            $result = replaceNaturalDisasterJsonKeyValuePairs($naturalDisasterStdClass, $patchDataStdClass);

            if($result == 0)
            {
                return $this->UpdateNaturalDisaster($playerId, $farmingBotId, $naturalDisasterStdClass);
            }
        }
        return -1;
    }
}

function handleRequests($segments, $request_type, $data = null)
{
    global $base_path;

    if (count($segments) === 3)
    {
        // Handle /players/{playerName}/stats
        if ($segments[0] === 'players' && isset($segments[1]) && $segments[2] == 'stats')
        {
            $playerName = urldecode($segments[1]);
            if($request_type == 'GET')
            {
                $service = new PlayerService();
                $playerStats = $service->GetPlayerStats($playerName);

                // Add hyperlinks for API crawling
                $xml = new DOMDocument();
                $xml->preserveWhiteSpace = false;
                $xml->formatOutput = true;
                $xml->loadXML($playerStats);
                $playerStatsElem = $xml->getElementsByTagName('stats')->item(0);

                $linksElem = $xml->createElement('links');
                $selfLink = $xml->createElement('link');
                $selfLink->setAttribute('rel', 'self');
                $selfLink->setAttribute('href', "{$base_path}/players/${playerName}/stats");
                $linksElem->appendChild($selfLink);

                // NOTE:: There are no more links for player stats
                $playerStatsElem->appendChild($linksElem);

                header('Content-Type: application/xml');
                print_r($xml->saveXML());
                http_response_code(200);
                return;
            }
            send404("HTTP Request Type not supported for /players/{playerName}/stats");
        }
    }
    else if (count($segments) === 5)
    {
        // Handle /players/{playerId}/farmingbots/{farmingBotId}/naturaldisasters
        if($segments[0] === 'players' && is_numeric($segments[1]) &&
           $segments[2] === 'farmingbots' && is_numeric($segments[3]) &&
           $segments[4] === 'naturaldisasters')
        {
            $playerId = $segments[1];
            $farmingBotId = $segments[3];

            if ($data === null)
            {
                send404("Request body was null\n");
            }

            if($request_type == 'POST')
            {
                $data_stdclass = json_decode($data);
                $service = new PlayerService();
                $created_natural_disaster_id = $service->CreateNaturalDisaster($playerId, $farmingBotId, $data_stdclass);

                $hateoas_data = [
                    "created_natural_disaster_id" => $created_natural_disaster_id,
                    "links" => [
                        [ "rel" => "self", "href" => "${base_path}/players/{$playerId}/farmingbots/{$farmingBotId}/naturaldisasters", "method" => "POST" ],
                        [ "rel" => "edit", "href" => "${base_path}/players/${playerId}/farmingbots/{$farmingBotId}/naturaldisasters/{$created_natural_disaster_id}", "method" => ["PUT", "GET"] ],
                        [ "rel" => "delete", "href" => "${base_path}/players/${playerId}/farmingbots/{$farmingBotId}/naturaldisasters/deletefrom/{your_timestamp}", "method" => "DELETE"],
                        [ "rel" => "get_connected_player_stats", "href" => "${base_path}/players/${playerId}/stats", "method" => "GET"],
                    ],
                ];

                header('Content-Type: application/json');
                print_r(json_encode($hateoas_data, JSON_PRETTY_PRINT));
                print_r("\n");
                http_response_code(201);
                return;
            }
            send404("Invalid PARAMS or HTTP Request Type not supported for /players/{playerId}/farmingbots/{farmingBotId} \
            naturaldisasters");
        }
    }
    else if(count($segments) === 6)
    {
        // Handle /players/{playerId}/farmingbots/{farmingBotId}/naturaldisasters/{naturalDisasterId}
        if($segments[0] === 'players' && is_numeric($segments[1]) &&
           $segments[2] === 'farmingbots' && is_numeric($segments[3]) &&
           $segments[4] === 'naturaldisasters' && is_numeric($segments[5]))
        {
            $playerId = $segments[1];
            $farmingBotId = $segments[3];
            $naturalDisasterId = $segments[5];

            if($data == null && $request_type != "GET")
            {
                send404("Request body was null!\n");
            }

            if($request_type == 'PUT')
            {
                $data_stdclass = json_decode($data);
                $service = new PlayerService();
                $updated_natural_disaster_stdclass = $service->UpdateNaturalDisaster($playerId, $farmingBotId, $data_stdclass);

                $hateoas_data = [
                    "updated_natural_disaster" => $updated_natural_disaster_stdclass,
                    "links" => [
                        [ "rel" => "self", "href" => "${base_path}/players/{$playerId}/farmingbots/{$farmingBotId}/naturaldisasters/${naturalDisasterId}", "method" => ["PUT", "GET"]],
                        [ "rel" => "add", "href" => "${base_path}/players/${playerId}/farmingbots/{$farmingBotId}/naturaldisasters", "method" => "POST"],
                        [ "rel" => "delete", "href" => "${base_path}/players/${playerId}/farmingbots/{$farmingBotId}/naturaldisasters/deletefrom/{your_timestamp}", "method" => "DELETE"],
                    ],
                ];

                header('Content-Type: application/json');
                print_r(json_encode($hateoas_data, JSON_PRETTY_PRINT));
                print_r("\n");
                http_response_code(201);
                return;
            }

            if($request_type == "GET")
            {
                $service = new PlayerService();
                $naturalDisaster = $service->GetNaturalDisaster($playerId, $farmingBotId, $naturalDisasterId);

                $hateoas_data = [
                    "naturalDisaster" => $naturalDisaster,
                    "links" => [
                        [ "rel" => "self", "href" => "${base_path}/players/{$playerId}/farmingbots/{$farmingBotId}/naturaldisasters/${naturalDisasterId}", "method" => ["GET", "PUT"]],
                        [ "rel" => "add", "href" => "${base_path}/players/${playerId}/farmingbots/{$farmingBotId}/naturaldisasters", "method" => "POST"],
                        [ "rel" => "delete", "href" => "${base_path}/players/${playerId}/farmingbots/{$farmingBotId}/naturaldisasters/deletefrom/{your_timestamp}", "method" => "DELETE"],
                    ],
                ];

                header('Content-Type: application/json');
                print_r(json_encode($hateoas_data, JSON_PRETTY_PRINT));
                print_r("\n");
                http_response_code(201);
                return;
            }

            if($request_type == "PATCH")
            {
                $service = new PlayerService();
                $patched_natural_disaster = $service->PatchNaturalDisaster($playerId, $farmingBotId, $naturalDisasterId, $data);

                $hateoas_data = [
                    "patched_natural_disaster" => $patched_natural_disaster,
                    "links" => [
                        [ "rel" => "self", "href" => "${base_path}/players/{$playerId}/farmingbots/{$farmingBotId}/naturaldisasters/${naturalDisasterId}", "method" => ["PATCH", "GET", "PUT"]],
                        [ "rel" => "add", "href" => "${base_path}/players/${playerId}/farmingbots/{$farmingBotId}/naturaldisasters", "method" => "POST"],
                        [ "rel" => "delete", "href" => "${base_path}/players/${playerId}/farmingbots/{$farmingBotId}/naturaldisasters/deletefrom/{your_timestamp}", "method" => "DELETE"],
                    ],
                ];

                header('Content-Type: application/json');
                print_r(json_encode($hateoas_data, JSON_PRETTY_PRINT));
                print_r("\n");
                http_response_code(201);
                return;
            }

            send404("Invalid PARAMS or HTTP Request Type not supported for /players/{playerId}/farmingbots/{farmingBotId} \
            naturaldisasters/{naturalDisasterId}");
        }
    }
    else if(count($segments) === 7)
    {
        // Handle /players/{playerId}/farmingbots/{farmingBotId}/
        // naturaldisasters/deletefrom/{timestamp}
        if($segments[0] === 'players' && is_numeric($segments[1]) &&
        $segments[2] === 'farmingbots' && is_numeric($segments[3]) &&
        $segments[4] === 'naturaldisasters' && $segments[5] === "deletefrom" &&
        DateTime::createFromFormat('Y-m-d\TH:i:s.u', $segments[6]) !== false)
        {
            $playerId = $segments[1];
            $farmingBotId = $segments[3];
            $timestamp = $segments[6];

            if($request_type == 'DELETE')
            {
                $service = new PlayerService();
                $deletedNaturalDisasterIds = $service->DeleteNaturalDisastersFromTimestamp($playerId, $farmingBotId, $timestamp);

                $hateoas_data = [
                    "deleted_natural_disaster_ids" => $deletedNaturalDisasterIds,
                    "links" => [
                        [ "rel" => "self", "href" => "${base_path}/players/{$playerId}/farmingbots/{$farmingBotId}/naturaldisasters/deletefrom/${timestamp}", "method" => "DELETE"],
                        [ "rel" => "add", "href" => "${base_path}/players/${playerId}/farmingbots/{$farmingBotId}/naturaldisasters", "method" => "POST"],
                        [ "rel" => "edit", "href" => "${base_path}/players/${playerId}/farmingbots/{$farmingBotId}/naturaldisasters/{your_natural_disaster_id}", "method" => ["PUT", "GET"]],
                    ],
                ];

                header('Content-Type: application/json');
                print_r(json_encode($hateoas_data, JSON_PRETTY_PRINT));
                print_r("\n");
                http_response_code(200);
                return;
            }
            send404("Invalid PARAMS or HTTP Request Type not supported for /players/{playerId}/farmingbots/{farmingBotId}\
            naturaldisasters/deletefrom/{timestamp}");
        }
    }
    // Make sure to return from all of the other valid branches
    send404("URI NOT FOUND");
}

handleRequests($segments, $method, $request_body);

?>