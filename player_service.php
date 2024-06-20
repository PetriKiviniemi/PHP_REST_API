<?php

// Parse the request URI
$request = $_SERVER['REQUEST_URI'];
$scriptName = $_SERVER['SCRIPT_NAME'];
$path = str_replace($scriptName, '', $request);
$path = trim($path, '/');
$segments = explode('/', $path);
$method = $_SERVER['REQUEST_METHOD'];
$request_body = file_get_contents('php://input');


function send404($message = "") {
    http_response_code(404);
    echo json_encode(['error' => 'Invalid request: ' . $message]);
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

function naturalDisasterStdClassToDOMNode($data, $xml) {
    try
    {
        $naturalDisasterElement = $xml->createElement('naturaldisaster');
        $naturalDisasterElement->setAttribute('id', $data->id);
        $naturalDisasterElement->setAttribute('name', $data->name);

        $durationElement = $xml->createElement('duration', $data->duration);
        $naturalDisasterElement->appendChild($durationElement);

        $dateTime = new DateTime("@{$data->timeoccured}");
        $dateTime->setTimezone(new DateTimeZone('UTC'));
        $formattedDateTime = $dateTime->format('Y-m-d\TH:i:s.u'); // Format to 'Y-m-d\TH:i:s.u'
        $timeOccurredElement = $xml->createElement('timeoccurred', $formattedDateTime);
        $naturalDisasterElement->appendChild($timeOccurredElement);

        foreach ($data->disasterDebuffs as $disasterDebuff) {
            $debuffElement = $xml->createElement('debuff');

            $uuidElement = $xml->createElement('uuid', $disasterDebuff->uuid);
            $debuffElement->appendChild($uuidElement);

            $descriptionElement = $xml->createElement('description', $disasterDebuff->description);
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
    } catch (Exception $e)
    {
        echo "Error parsing NaturalDisaster StdClass: {$e}";
        return null;
    }
}

function NaturalDisasterDOMToStdClass ($NDDom)
{
    $updatedNaturalDisasterStdClass = new StdClass;
    $updatedNaturalDisasterStdClass->id = $NDDom->getAttribute('id');
    $updatedNaturalDisasterStdClass->name = $NDDom->getAttribute('name');
    $updatedNaturalDisasterStdClass->duration = $NDDom->getElementsByTagName('duration')->item(0)->nodeValue;
    $updatedNaturalDisasterStdClass->timeoccured= $NDDom->getElementsByTagName('timeoccured')->item(0)->nodeValue;
    $debuffObject = $NDDom->getElementsByTagName("debuff")->item(0);

    $updatedNaturalDisasterStdClass->debuff = new StdClass;
    $updatedNaturalDisasterStdClass->debuff->uuid = $debuffObject->getElementsByTagName("uuid")->item(0)->nodeValue;
    $updatedNaturalDisasterStdClass->debuff->description = $debuffObject->getElementsByTagName("description")->item(0)->nodeValue;

    $effectsElement = $debuffObject->getElementsByTagName("effects")->item(0);
    $updatedNaturalDisasterStdClass->debuff->effects = [];

    if ($effectsElement) {
        // Get all 'effect' nodes within the 'effects' element
        $effectNodes = $effectsElement->getElementsByTagName("effect");

        foreach ($effectNodes as $effectNode) {
            $effectObj = new StdClass;
            $effectObj->effect = $effectNode->nodeValue;

            // Add the effect object to the effects array
            $updatedNaturalDisasterStdClass->debuff->effects[] = $effectObj;
        }
    }

    return $updatedNaturalDisasterStdClass;
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

            $originalNaturalDisaster = $updatedNaturalDisaster;
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
}

function handleRequests($segments, $request_type, $data = null)
{
    if (count($segments) === 3)
    {
        // Handle /players/{playerName}/stats
        if ($segments[0] === 'players' && isset($segments[1]) && $segments[2] == 'stats')
        {
            $playerName = urldecode($segments[1]);
            if($request_type == 'GET')
            {
                $service = new PlayerService();
                print_r($service->GetPlayerStats($playerName) . "\n");
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
                print_r($service->CreateNaturalDisaster($playerId, $farmingBotId, $data_stdclass));
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

            if($data == null)
            {
                send404("Request body was null!\n");
            }

            if($request_type == 'PUT')
            {
                $data_stdclass = json_decode($data);
                $service = new PlayerService();
                $updated_natural_disaster_stdclass = $service->UpdateNaturalDisaster($playerId, $farmingBotId, $data_stdclass);
                print_r(json_encode($updated_natural_disaster_stdclass));
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
                print_r(json_encode($deletedNaturalDisasterIds));
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