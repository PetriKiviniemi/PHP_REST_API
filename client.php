<?php

// DANGEROUS! - Use next lines only for debugging purposes.
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$rest_api_url = "https://wwwlab.cs.univie.ac.at/~kiviniemip35/player_service.php";

class Debuff{
    public $uuid;
    public $description;
    public $effects;

    public function __construct($uuid, $description, $effects)
    {
        $this->uuid = $uuid;
        $this->description = $description;
        $this->effects = $effects;
    }
}

class NaturalDisasterData {
    public $id;
    public $name;
    public $duration;
    public $timeoccured;
    public $disasterDebuffs;

    public function __construct($id, $name, $duration, $timeoccured, $disasterDebuffs)
    {
        $this->id = $id;
        $this->name = $name;
        $this->duration = $duration;
        $this->timeoccured = $timeoccured;
        $this->disasterDebuffs = $disasterDebuffs;
    }
}

function GET_OPERATION()
{
    global $rest_api_url;

    try
    {

        $playerName = urlencode("Charles Lee");
        $player_stats_url = rtrim($rest_api_url, '/') . "/players/{$playerName}/stats";

        $curl = curl_init();

        curl_setopt($curl, CURLOPT_URL, $player_stats_url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPGET, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: text/plain'));

        $response = curl_exec($curl);

        if(curl_errno($curl)) 
        {
            print_r('Curl error: ' . curl_error($curl));
        }
        else
        {
            echo '<pre>';
            echo htmlentities($response);
            echo '</pre>';
        }

        curl_close($curl);
    } 
    catch (Exception $e) {
        print_r('Error: ' . $e->getMessage());
    }
}

function POST_OPERATION()
{
    global $rest_api_url;

    try {
        //Create a natural disaster and it's debuffs
        $debuffs = array();
        array_push($debuffs,
            new Debuff(
                "255a7517-6762-47e3-86cd-e59bdd83b8bc",
                "Bleeding debuff",
                array("Lose one health every 2 minutes"),
            )
        );

        $naturalDisasterData = new NaturalDisasterData(
            15, "Earthquake", 20.123,
            "1717429017", $debuffs
        );

        $post_data = json_encode($naturalDisasterData);
        $playerId = 1;
        $farmingBotId = 1;

        $natural_disaster_url = rtrim($rest_api_url, '/') . "/players/{$playerId}/farmingbots/{$farmingBotId}/naturaldisasters";

        $curl = curl_init();

        curl_setopt($curl, CURLOPT_URL, $natural_disaster_url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $post_data);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($post_data)
        ));

        $response = curl_exec($curl);
        print_r($response);

        if(curl_errno($curl)) 
        {
            print_r('Curl error: ' . curl_error($curl));
        }
        else
        {
            echo '<pre>';
            echo htmlspecialchars($response);
            echo '</pre>';
        }

        curl_close($curl);
    }
    catch (Exception $e) {
        print_r('Error: ' . $e->getMessage());
    }
}

function PUT_OPERATION()
{
    global $rest_api_url;

    try
    {
        //Create a natural disaster and it's debuffs
        $debuffs = array();
        array_push($debuffs,
            new Debuff(
                "255a7517-6762-47e3-86cd-e59bdd83b8bc",
                "Bleeding debuff",
                array("Lose one health every 2 minutes"),
            )
        );

        // We are using existing ID, so we should update the 
        // Original disaster with ID 1 to this new one
        $naturalDisasterId = 1;
        $playerId = 1;
        $farmingBotId = 1;

        $naturalDisasterData = new NaturalDisasterData(
            $naturalDisasterId, "Latest Earthquake", 20.123,
            1717429017, $debuffs
        );

        $put_data = json_encode($naturalDisasterData);
        $natural_disaster_url = rtrim($rest_api_url, '/') . "/players/{$playerId}/farmingbots/{$farmingBotId}/naturaldisasters/{$naturalDisasterId}";

        $curl = curl_init();

        curl_setopt($curl, CURLOPT_URL, $natural_disaster_url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($curl, CURLOPT_POSTFIELDS, $put_data);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($put_data)
        ));

        $response = curl_exec($curl);
        print_r($response);

        if(curl_errno($curl)) 
        {
            print_r('Curl error: ' . curl_error($curl));
        }
        else
        {
            echo '<pre>';
            echo htmlspecialchars($response);
            echo '</pre>';
        }

        curl_close($curl);

    }
    catch (Exception $e) {
        print_r('Error: ' . $e->getMessage());
    }
}

function DELETE_OPERATION()
{
    global $rest_api_url;

    try
    {
        $playerId = 1;
        $farmingBotId = 1;
        $timestamp = "2020-10-09T23:55:15.708769";
        $natural_disaster_url = rtrim($rest_api_url, '/') . "/players/{$playerId}/farmingbots/{$farmingBotId}/naturaldisasters/deletefrom/{$timestamp}";

        $curl = curl_init();

        curl_setopt($curl, CURLOPT_URL, $natural_disaster_url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'DELETE');

        $response = curl_exec($curl);
        print_r($response);

        if(curl_errno($curl)) 
        {
            print_r('Curl error: ' . curl_error($curl));
        }
        else
        {
            echo '<pre>';
            echo htmlspecialchars($response);
            echo '</pre>';
        }

        curl_close($curl);
    } 
    catch (Exception $e) {
        print_r('Error: ' . $e->getMessage());
    }
}

function PATCH_OPERATION()
{

}

if (isset($_POST['action'])) {
  $action = $_POST['action'];
  
  // Implement your CRUD logic based on the action
  // This is a basic example, you'll need to replace it with your actual functionality
  switch ($action) {
    case 'POST':
      POST_OPERATION();
      break;
    case 'GET':
      GET_OPERATION();
      break;
    case 'PUT':
      PUT_OPERATION();
      break;
    case 'PATCH':
      PATCH_OPERATION();
      break;
    case 'DELETE':
      DELETE_OPERATION();
      break;
    default:
      $message = "Invalid action!";
  }
}

?>
