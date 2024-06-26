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
$naturaldisaster_jsonschema_postput = 'ND_POST_PUT_Schema.json';
$naturaldisaster_jsonschema_patch = 'ND_PATCH_Schema.json';

function sendError($code, $message = "") {
    http_response_code($code);
    echo json_encode(['error' => 'Invalid request: ' . $message]);
}

function isValidUUID($uuid) {
    $regex = '/^[0-9a-f]{8}-[0-9a-f]{4}-[4][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
    return preg_match($regex, $uuid) === 1;
}

function validate_jsonschema($json_input, $jsonschema_filepath)
{
    $tempFile = tempnam(sys_get_temp_dir(), 'json_input_') . '.json';
    file_put_contents($tempFile, $json_input);

    $command = "python3 validate_json.py $jsonschema_filepath $tempFile";

    exec($command, $output, $returnCode);

    unlink($tempFile);
    return $returnCode;
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
        // If we find an array or object
        if (is_object($value) && is_object($original->{$key}))
        {
            replaceNaturalDisasterJsonKeyValuePairs($original->{$key}, $value);
        }
        else if(is_array($value) && is_array($original->{$key}))
        {
            foreach($value as $patch_item)
            {
                // Iterate through the list
                // Look for object with same uuid or id and patch it's data
                if(is_object($patch_item))
                {
                    $found = false;
                    foreach($original->{$key} as $original_item)
                    {
                        if($patch_item->uuid === $original_item->uuid)
                        {
                            replaceNaturalDisasterJsonKeyValuePairs($original_item, $patch_item);
                            $found = true;
                            break;
                        }
                    }
                    if(!$found)
                    {
                        $original->{$key}[] = $patch_item;
                    }
                }
                else
                {
                    $original->{$key} = $value;
                }
            }
        }
        else
        {
            // Update the object key with patch value
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

        // Depending are we dealing with epoch or normal UTC timestamp
        if (strpos($data->timeoccurred, 'Z') !== false) {
            $dateTime = new DateTime($data->timeoccurred);
        } else {
            $dateTime = new DateTime();
            $dateTime->setTimestamp($data->timeoccurred);
        }
        $dateTime->setTimezone(new DateTimeZone('UTC'));
        $formattedDateTime = $dateTime->format('Y-m-d\TH:i:s.u') . 'Z'; // Format to 'Y-m-d\TH:i:s.u'
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

    /*
    GetPlayerStats(name: string)
    URI: https://wwwlab.cs.univie.ac.at/~kiviniemip35/player_service.php/players/{playerId}/stats
    HTTP-Method: GET 
    Request-Body: Null
    Response-Body: XML
    <stats>
        <name>String</name>
        <experience>Float</experience>
        <creation_date>Timestamp</creation_date>
        <links>
            <link rel=String href=String />
        </links>
    </stats>
    */
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

    /*
    GetNaturalDisaster(playerId: int, farmingBotId: int, naturalDisasterId: int)
    URI: https://wwwlab.cs.univie.ac.at/~kiviniemip35/player_service.php/players/{playerId}/farmingbots/{farmingBotId}/naturaldisasters/{naturalDisasterId}
    HTTP-Method: GET 
    Request-Body: Null
    Response-Body:
    {
        "naturalDisaster": {
            "id": int,
            "name": float,
            "timeoccurred": timestamp,
            "disasterDebuffs": [
                "debuff": {
                    "uuid": string (uuid-format),
                    "description": string,
                    "effects": [
                        string
                    ]
                }
            ]
        }
        "links": [
            {
                "rel": string,
                "href": string,
                "method": string / array of strings
            },
        ]
    }
    */
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
    CreateNaturalDisaster(playerId: int, farmingBotId: int, naturalDisasterData: customComplexType)
    URI: https://wwwlab.cs.univie.ac.at/~kiviniemip35/player_service.php/players/{playerId}/farmingbots/{farmingBotId}/naturaldisasters
    HTTP-Method: POST
    Request-Body:
    {
        "id": int,
        "name": float,
        "timeoccurred": timestamp,
        "disasterDebuffs": [
            "debuff": {
                "uuid": string (uuid-format),
                "description": string,
                "effects": [
                    string
                ]
            }
        ]
    }
    Response-Body:
    {
        "created_natural_disaster_id": int,
        "links": [
            {
                "rel": string,
                "href": string,
                "method": string / array of strings
            },
        ]
    }
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

    /*
    UpdateNaturalDisaster(playerId: int, farmingBotId: int, naturalDisasterData: customComplexType)
    URI: https://wwwlab.cs.univie.ac.at/~kiviniemip35/player_service.php/players/{playerId}/farmingbots/{farmingBotId}/naturaldisasters/{naturalDisasterId}
    HTTP-Method: PUT 
    Request-Body:
    {
        "id": int,
        "name": float,
        "timeoccurred": timestamp,
        "disasterDebuffs": [
            "debuff": {
                "uuid": string (uuid-format),
                "description": string,
                "effects": [
                    string
                ]
            }
        ]
    }
    Response-Body:
    {
        "updated_natural_disaster": {
            "id": int,
            "name": float,
            "timeoccurred": timestamp,
            "disasterDebuffs": [
                "debuff": {
                    "uuid": string (uuid-format),
                    "description": string,
                    "effects": [
                        string
                    ]
                }
            ]
        },
        "links": [
            {
                "rel": string,
                "href": string,
                "method": string / array of strings
            },
        ]
    }
    */
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

    /*
    DeleteNaturalDisastersFromTimestamp(playerId: int, farmingBotId: int, timestamp: string)
    URI: https://wwwlab.cs.univie.ac.at/~kiviniemip35/player_service.php/players/{playerId}/farmingbots/{farmingBotId}/naturaldisasters/deletefrom/{timestamp}
    HTTP-Method: DELETE
    Request-Body: Null
    Response-Body:
    {
        "deleted_natural_disaster_ids": [ int ],
        "links": [
            {
                "rel": string,
                "href": string,
                "method": string / array of strings
            },
        ]
    }
    */
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

    /*
    PatchNaturalDisaster(playerId: int, farmingBotId: int, naturalDisasterId: int, naturalDisasterData: customComplexType)
    URI: https://wwwlab.cs.univie.ac.at/~kiviniemip35/player_service.php/players/{playerId}/farmingbots/{farmingBotId}/naturaldisasters/{naturalDisasterId}
    HTTP-Method: PATCH
    Request-Body: NOTE:: THIS CAN BE FULL OR PARTIAL OBJECT
    {
        "id": int,
        "name": float,
        "timeoccurred": timestamp,
        "disasterDebuffs": [
            "debuff": {
                "uuid": string (uuid-format),
                "description": string,
                "effects": [
                    string
                ]
            }
        ]
    }
    Response-Body:
    {
        "patched_natural_disaster": {
            "id": int,
            "name": float,
            "timeoccurred": timestamp,
            "disasterDebuffs": [
                "debuff": {
                    "uuid": string (uuid-format),
                    "description": string,
                    "effects": [
                        string
                    ]
                }
            ]
        },
        "links": [
            {
                "rel": string,
                "href": string,
                "method": string / array of strings
            },
        ]
    }
    */
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

    /*
    GetDebuffByUUID(playerId: int, farmingBotId: int, naturalDisasterId: int, uuid: string)
    URI: https://wwwlab.cs.univie.ac.at/~kiviniemip35/player_service.php/players/{playerId}/farmingbots/{farmingBotId}/naturaldisasters/{naturalDisasterId}/debuffs/{uuid}
    HTTP-Method: GET 
    Request-Body: Null
    Response-Body:
    {
        "debuff": {
            "uuid": string (uuid-format),
            "description": string,
            "effects": [
                string
            ]
        },
        "links": [
            {
                "rel": string,
                "href": string,
                "method": string / array of strings
            },
        ]
    }
    */
    public function GetDebuffByUUID($playerId, $farmingBotId, $naturalDisasterId, $uuid)
    {
        $naturalDisaster = $this->GetNaturalDisaster($playerId, $farmingBotId, $naturalDisasterId);
        if($naturalDisaster)
        {
            foreach($naturalDisaster->disasterDebuffs as $debuff)
            {
                if($debuff->uuid == $uuid)
                {
                    return $debuff;
                }
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
            sendError(405, "HTTP Request Type not supported for /players/{playerName}/stats");
            return;
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
                sendError(403, "Request body was null");
                return;
            }

            if($request_type == 'POST')
            {
                global $naturaldisaster_jsonschema_postput;

                if(validate_jsonschema($data, $naturaldisaster_jsonschema_postput) == 1)
                {
                    sendError(403, "ERROR:: FAILED TO VALIDATE REQUEST BODY FOR POST");
                    return;
                }

                $data_stdclass = json_decode($data);
                $service = new PlayerService();
                $created_natural_disaster_id = $service->CreateNaturalDisaster($playerId, $farmingBotId, $data_stdclass);
                if($created_natural_disaster_id == -1)
                {
                    sendError(403, "ERROR: FAILED TO CREATE NATURAL DISASTER");
                    return;
                }

                $hateoas_data = [
                    "created_natural_disaster_id" => $created_natural_disaster_id,
                    "links" => [
                        [ "rel" => "self", "href" => "${base_path}/players/{$playerId}/farmingbots/{$farmingBotId}/naturaldisasters", "method" => "POST" ],
                        [ "rel" => "edit_ND", "href" => "${base_path}/players/${playerId}/farmingbots/{$farmingBotId}/naturaldisasters/{$created_natural_disaster_id}", "method" => ["PUT", "GET", "PATCH"] ],
                        [ "rel" => "delete_ND", "href" => "${base_path}/players/${playerId}/farmingbots/{$farmingBotId}/naturaldisasters/deletefrom/{your_timestamp}", "method" => "DELETE"],
                        [ "rel" => "get_player_stats", "href" => "${base_path}/players/${playerId}/stats", "method" => "GET"],
                    ],
                ];

                header('Content-Type: application/json');
                print_r(json_encode($hateoas_data, JSON_PRETTY_PRINT));
                print_r("\n");
                http_response_code(201);
                return;
            }
            sendError(405, "Invalid PARAMS or HTTP Request Type not supported for /players/{playerId}/farmingbots/{farmingBotId} \
            naturaldisasters");
            return;
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
                sendError(403, "Request body was null!");
                return;
            }

            if($request_type == 'PUT')
            {
                global $naturaldisaster_jsonschema_postput;
                if(validate_jsonschema($data, $naturaldisaster_jsonschema_postput) == 1)
                {
                    sendError(403, "ERROR:: FAILED TO VALIDATE REQUEST BODY FOR PUT");
                    return;
                }

                $data_stdclass = json_decode($data);
                $service = new PlayerService();
                $updated_natural_disaster_stdclass = $service->UpdateNaturalDisaster($playerId, $farmingBotId, $data_stdclass);

                $hateoas_data = [
                    "updated_natural_disaster" => $updated_natural_disaster_stdclass,
                    "links" => [
                        [ "rel" => "self", "href" => "${base_path}/players/{$playerId}/farmingbots/{$farmingBotId}/naturaldisasters/${naturalDisasterId}", "method" => ["PUT", "GET", "PATCH"]],
                        [ "rel" => "add_ND", "href" => "${base_path}/players/${playerId}/farmingbots/{$farmingBotId}/naturaldisasters", "method" => "POST"],
                        [ "rel" => "delete_ND", "href" => "${base_path}/players/${playerId}/farmingbots/{$farmingBotId}/naturaldisasters/deletefrom/{your_timestamp}", "method" => "DELETE"],
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
                        [ "rel" => "self", "href" => "${base_path}/players/{$playerId}/farmingbots/{$farmingBotId}/naturaldisasters/${naturalDisasterId}", "method" => ["GET", "PUT", "PATCH"]],
                        [ "rel" => "add_ND", "href" => "${base_path}/players/${playerId}/farmingbots/{$farmingBotId}/naturaldisasters", "method" => "POST"],
                        [ "rel" => "delete_ND", "href" => "${base_path}/players/${playerId}/farmingbots/{$farmingBotId}/naturaldisasters/deletefrom/{your_timestamp}", "method" => "DELETE"],
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
                global $naturaldisaster_jsonschema_patch;
                if(validate_jsonschema($data, $naturaldisaster_jsonschema_patch) == 1)
                {
                    sendError(403, "ERROR:: FAILED TO VALIDATE REQUEST BODY FOR PATCH");
                    return;
                }

                $service = new PlayerService();
                $patched_natural_disaster = $service->PatchNaturalDisaster($playerId, $farmingBotId, $naturalDisasterId, $data);

                $hateoas_data = [
                    "patched_natural_disaster" => $patched_natural_disaster,
                    "links" => [
                        [ "rel" => "self", "href" => "${base_path}/players/{$playerId}/farmingbots/{$farmingBotId}/naturaldisasters/${naturalDisasterId}", "method" => ["PATCH", "GET", "PUT"]],
                        [ "rel" => "add_ND", "href" => "${base_path}/players/${playerId}/farmingbots/{$farmingBotId}/naturaldisasters", "method" => "POST"],
                        [ "rel" => "delete_ND", "href" => "${base_path}/players/${playerId}/farmingbots/{$farmingBotId}/naturaldisasters/deletefrom/{your_timestamp}", "method" => "DELETE"],
                    ],
                ];

                header('Content-Type: application/json');
                print_r(json_encode($hateoas_data, JSON_PRETTY_PRINT));
                print_r("\n");
                http_response_code(201);
                return;
            }

            sendError(405, "Invalid PARAMS or HTTP Request Type not supported for /players/{playerId}/farmingbots/{farmingBotId} \
            naturaldisasters/{naturalDisasterId}");
            return;
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
                        [ "rel" => "add_ND", "href" => "${base_path}/players/${playerId}/farmingbots/{$farmingBotId}/naturaldisasters", "method" => "POST"],
                        [ "rel" => "edit_ND", "href" => "${base_path}/players/${playerId}/farmingbots/{$farmingBotId}/naturaldisasters/{your_natural_disaster_id}", "method" => ["PUT", "GET", "PATCH"]],
                    ],
                ];

                header('Content-Type: application/json');
                print_r(json_encode($hateoas_data, JSON_PRETTY_PRINT));
                print_r("\n");
                http_response_code(200);
                return;
            }
            sendError(405, "Invalid PARAMS or HTTP Request Type not supported for /players/{playerId}/farmingbots/{farmingBotId}\
            naturaldisasters/deletefrom/{timestamp}");
            return;
        }
    }
    else if(count($segments) === 8)
    {
        // Handle /players/{playerId}/farmingbots/{farmingBotId}/
        // naturaldisasters/{naturalDisasterId}/debuffs/{uuid}
        if($segments[0] === 'players' && is_numeric($segments[1]) &&
        $segments[2] === 'farmingbots' && is_numeric($segments[3]) &&
        $segments[4] === 'naturaldisasters' && is_numeric($segments[5]) &&
        $segments[6] === 'debuffs' && isValidUUID($segments[7]))
        {
            $playerId = $segments[1];
            $farmingBotId = $segments[3];
            $naturalDisasterId = $segments[5];
            $debuffUUID = $segments[7];

            if($request_type === "GET")
            {
                $service = new PlayerService();
                $debuff = $service->GetDebuffByUUID($playerId, $farmingBotId, $naturalDisasterId, $debuffUUID);

                $hateoas_data = [
                    "debuff" => $debuff,
                    "links" => [
                        [ "rel" => "self", "href" => "${base_path}/players/{$playerId}/farmingbots/{$farmingBotId}/naturaldisasters/debuffs/${debuffUUID}", "method" => "GET"],
                        [ "rel" => "delete_ND", "href" => "${base_path}/players/{$playerId}/farmingbots/{$farmingBotId}/naturaldisasters/deletefrom/{timestamp}", "method" => "DELETE"],
                        [ "rel" => "add_ND", "href" => "${base_path}/players/${playerId}/farmingbots/{$farmingBotId}/naturaldisasters", "method" => "POST"],
                        [ "rel" => "edit_ND", "href" => "${base_path}/players/${playerId}/farmingbots/{$farmingBotId}/naturaldisasters/${naturalDisasterId}", "method" => ["PUT", "GET", "PATCH"]],
                    ],
                ];

                header('Content-Type: application/json');
                print_r(json_encode($hateoas_data, JSON_PRETTY_PRINT));
                print_r("\n");
                http_response_code(200);
                return;
            }

            sendError(405, "Invalid PARAMS or HTTP Request Type not supported for /players/{playerId}/farmingbots/{farmingBotId}\
            naturaldisasters/debuffs/{uuid}");
            return;
        }
    }
    // Make sure to return from all of the other valid branches
    sendError(404, "URI NOT FOUND");
    return;
}

handleRequests($segments, $method, $request_body);

?>