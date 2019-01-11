<?php

namespace Drupal\postal_search\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\geocoder\Geocoder;
use Drupal\Core\Database\Database;
use Drupal\node\Entity\Node;


class PostalSearchController extends ControllerBase
{
    /** @var Drupal\Core\Database\Database $connection **/
    protected $connection;

    /**
     * Implements __construct().
     */
    public function __construct() {
        $this->connection = Database::getConnection();
    }

    /**
     * Finds the link for the input postal code using the db table
     *
     * @param string $postal
     * @return jsonResponse
     */
    public function search($postal) {

        $cleanPostal = preg_replace('/[^A-Za-z0-9]/', '', $postal); 

        $firstChar = strtolower($cleanPostal[0]);

        if($firstChar == "m") {
            $responseData = ["title" => 'Toronto', "link" => "http://alz.to/"];
            return new JsonResponse($responseData);  
        }

        $sql = "SELECT link,guid FROM postal_code_links 
                WHERE postal_code LIKE ?";
        $name = "";

        $result = $this->connection->query($sql,[$cleanPostal]);
        $result->allowRowCount = TRUE;

        if($result->rowCount()<1) {
            $postalStart = substr($cleanPostal, 0, 3);
            $sql = "SELECT link, guid FROM postal_code_links 
                    WHERE postal_code LIKE ?";

            $result = $this->connection->query($sql,[$postalStart]);
            $result->allowRowCount = TRUE;
        }

        if($result->rowCount()>0) {
            $resultObj = $result->fetchObject();

            if ($resultObj->guid) {
                $sql = "SELECT gfss.field_society_suffix_value FROM groups g
                    JOIN group__field_society_suffix gfss ON g.id=gfss.entity_id
                    WHERE uuid=?;";
                $res = $this->connection->query($sql,[$resultObj->guid]);
                $res->allowRowCount = TRUE;

                if($result->rowCount()>0) {
                    $resObj = $res->fetchObject();
                    $name = $resObj->field_society_suffix_value;
                }
            }

            if (!$name) {
                $name = $resultObj->link;
            }

            $responseData = ["title" => $name, "link" => $resultObj->link];
            return new JsonResponse($responseData); 
        } else {
            return new JsonResponse(['nope' => true]);
        }
    }
}