<?php
namespace Drupal\zaboutme;

use Drupal\user\Entity\User;
use Drupal\Core\Database\Database;
use Drupal\taxonomy\Entity\Term;
use Drupal\node\Entity\Node;
use Drupal\media_entity\Entity\Media;
use Drupal\menu_link_content\Entity\MenuLinkContent;

class SqlServer {
  public $conn;
  
  public $users = [];
  
  public $missing = [];
  
  private $current_item = NULL;
  
  public function __construct() {
    $this->connect();
  }
  
  public function __destruct() {
    sqlsrv_close($this->conn);
    dpm($this->missing);
  }
  
  /**
   * Query db and return array of objects
   * 
   * @param type $q
   * @param string $index which key to use for the index - numeric array if not specified
   * @param type $page_size
   * @param type $page_num
   * @return array
   */
  public function queryObjects($q, $index = null, $lang_grouping = FALSE, $page_size = null, $page_num = null )
  {
    //add paging offset
    if ($page_size && $page_num)
    {
      $q .= " OFFSET {$page_size} * ({$page_num} - 1) ROWS FETCH NEXT {$page_size} ROWS ONLY";
    }
    
    $getResults= sqlsrv_query( $this->conn, $q );

    if ( $getResults == FALSE )
        die( $this->formatErrors( sqlsrv_errors()));
    
    $data = [];
    if ($index)
    {
      if ($lang_grouping)
      {
        $shared = [];
        while ( $row = sqlsrv_fetch_object( $getResults)) {
          if($row->language) { $data[$row->language][$row->$index] = $row; }
          else { $shared[$row->$index] = $row; }
        }
        if (!empty($data['en'])) $data['en'] += $shared;
        if (!empty($data['fr'])) $data['fr'] += $shared;
      }
      else {
        while ( $row = sqlsrv_fetch_object( $getResults)) {
          $data[$row->$index] = $row;
        }
      }
      
    }
    else {
      while ( $row = sqlsrv_fetch_object( $getResults)) {
        array_push($data, $row);
      }
    }
    
    sqlsrv_free_stmt( $getResults );
    
    return $data;
  }
  
  /**
   * Query db and return array of data
   * 
   * @param type $q
   * @return array
   */
  public function queryArrayField($q)
  {
    $stmt = sqlsrv_query( $this->conn, $q );

    if ( $stmt == FALSE )
        die( $this->formatErrors( sqlsrv_errors()));
    
    $data = [];

    while ( sqlsrv_fetch( $stmt)) {
      array_push($data, sqlsrv_get_field( $stmt, 0));
    }
    
    sqlsrv_free_stmt( $stmt );
    
    return $data;
  }
  
  /**
   * Query db for a single column
   * 
   * @param type $q
   * @return array
   */
  public function queryColumnStream($q)
  {
    $stmt= sqlsrv_query( $this->conn, $q );

    if ( $stmt == FALSE )
        die( $this->formatErrors( sqlsrv_errors()));

    $c = '';
    while (sqlsrv_fetch( $stmt)) {
      $c .= stream_get_contents(sqlsrv_get_field($stmt, 0, SQLSRV_PHPTYPE_STREAM(SQLSRV_ENC_BINARY)));
    }
    
    sqlsrv_free_stmt( $stmt );
    
    return $c;
  }
  
  /**
   * Create terms with parents
   * 
   * @param type $vocab_id
   * @param type $items
   */
  public function createTerms($vocab_id, $items)
  {
    $parents = array();
        
    foreach ($items as $item) {
      $exists = \Drupal::service('entity.repository')->loadEntityByUuid('taxonomy_term', $item->id);
      if (empty($exists))
      {
        $term = Term::create(array(
          'parent' => empty($parents[$item->parentid]) ? array() : array($parents[$item->parentid]),
          'name' => $item->name,
          'vid' => $vocab_id,
          'uuid' => strtolower($item->id)
        ));

        $term->save();
        $parents[$item->id] = $term->id();
      }
      else {
        $parents[$item->id] = $exists->id();
      }
    }
  }
  
  /**
   * Import folder structure for specific content
   * 
   * @param string $vocab_id
   * @param mixed $template_id tempalte id or array of ids
   * @param string $root_item
   */
  public function importTerms($vocab_id, $template_id, $root_item)
  {
    if (is_array($template_id)) $template_id = implode("','", $template_id);
    
    $items = $this->queryObjects("
      WITH abc AS
      (
       SELECT a.Id, a.parentid, a.Name
        FROM Items a 
        where TemplateID in ('$template_id')
      )
      ,cte AS 
      (
        SELECT a.Id, a.ParentId, a.name
        FROM Items a
        WHERE Id = '{$root_item}'
        UNION ALL
        SELECT a.Id, a.parentid, a.Name
        FROM abc a
        JOIN cte c ON a.parentId = c.id
      )
      SELECT parentid, id, name FROM cte WHERE id <> '{$root_item}'");
      dpm($items);
    $this->createTerms($vocab_id, $items);
  }
  
  
  
  /**
   * Import media
   * 
   * @param string $type
   * @param string $date min date to filter out records (format yyy-mm-dd hh:mm)
   * @param string $lang language
   * @param string $id uuid of media to create/update
   * @param bool $include_existing
   */
  public function importMedia($type, $date = NULL, $lang = 'en', $id = NULL, $include_existing = FALSE, $page_size = NULL, $page_num = NULL)
  {
    $media = 'document';
    
    $sql = "SELECT i.id, i.name, i.parentid, i.created, i.updated, s.value as blobid, s2.value as ext, i.templateid
        FROM Items i 
          INNER JOIN SharedFields s ON i.ID = s.ItemId AND s.FIeldId = '40E50ED9-BA07-4702-992E-A912738D32DC'
          INNER JOIN SharedFields s2 ON i.ID = s2.ItemId AND s2.FIeldId = 'C06867FE-9A43-4C7D-B739-48780492D06F'";
    if ($id)
    {
      $sql .= " WHERE i.ID = '{$id}'";
    }
    else {
      switch ($type)
      {
        case 'image':
          $template = "'DAF085E8-602E-43A6-8299-038FF171349F','F1828A2C-7E5D-4BBD-98CA-320474871548'";
          $media = 'image';
          break;
        case 'pdf':
           $template = "'0603F166-35B8-469F-8123-E8D87BEDC171'";
          break;
        case 'doc':
          $template = "'16692733-9A61-45E6-B0D4-4C0C06F8DD3C'";
          break;
        case 'docx':
          $template = "'7BB0411F-50CD-4C21-AD8F-1FCDE7C3AFFE'";
          break;
        case 'file':
          $template = "'962B53C4-F93B-4DF9-9821-415C867B8903'";
          break;
        case 'mp3':
          $template = "'B60424A5-CE06-4C2E-9F49-A6D732F55D4B'";
          break;
        case 'zip':
          $template = "'4F4A3A3B-239F-4988-98E1-DA3779749CBC'";
          break;
      }
      $sql .= " WHERE i.TemplateID IN ({$template})";
    }
    
    if (!empty($date)) { $sql .= " AND i.updated > '{$date}'"; }

    $items = $this->queryObjects($sql . ' ORDER BY i.updated asc', 'id', FALSE, $page_size, $page_num);
    $media_entity = NULL;
    
    foreach ($items as $item)
    {
      //don't store empty media
      if (empty($item->blobid)) continue;
     
      //set media bundle based on item template
      if ($id && in_array($item->templateid, ['DAF085E8-602E-43A6-8299-038FF171349F','F1828A2C-7E5D-4BBD-98CA-320474871548']))
      {
        $media = 'image';
      }
      
      try {
        $media_entity = \Drupal::service('entity.repository')->loadEntityByUuid('media', strtolower($item->id));
        if (empty($media_entity))
        {
            $term = \Drupal::service('entity.repository')->loadEntityByUuid('taxonomy_term', $item->parentid);
            if (empty($term)) continue;

            $parents = \Drupal::service('entity_type.manager')
              ->getStorage("taxonomy_term")
              ->loadAllParents($term->id());

            $path = array();
            foreach ($parents as $parent) {
              array_unshift($path, strtolower($parent->getName()));
            }
            
            //put banners in their own folder
            if ($term->getVocabularyId() == 'banners') { array_unshift($path, 'banners'); }

            $fields = $this->fields($item->id, $lang);
            $media_entity = $this->blobToFile($item, $fields, $media_entity, $media, implode('/', $path), $lang);
        }
        elseif ($include_existing) {
          $fields = $this->fields($item->id, $lang);
          $media_entity = $this->blobToFile($item, $fields, $media_entity, $media);
        }
      }
      catch(Exception $ex)
      {
        dpm($item);
        dpm($ex->getMessage());
      }
      if (empty(!$id)) { $media_entity = NULL; }
    }
    
    return $media_entity;
  }
  
  /**
   * Convert blob to a media file
   * 
   * @param array $item
   * @param array $fields
   * @param object $media_entity
   * @param string $media
   * @param byte $blob
   * @param string $path
   * @param string $lang
   * @return type
   */
  public function blobToFile(&$item, &$fields, &$media_entity, $media = NULL, $path = NULL, $lang = 'en')
  {
    if (!empty($media_entity)) { 
      if (!empty($media_entity->field_image->entity)) { $uri = $media_entity->field_image->entity->getFileUri(); }
      elseif (!empty($media_entity->field_document->entity)) { $uri = $media_entity->field_document->entity->getFileUri(); }
    }
    elseif ($path && $item->name && $item->ext) 
    { 
      $path = 'public://' . strtolower($path);
      file_prepare_directory($path, FILE_CREATE_DIRECTORY);
      $uri = $path . '/' . strtolower($item->name) . '.' . strtolower($item->ext); 
    }

    if (!empty($uri))
    {
      //get the blob data
      $blobid = $this->parseId($item->blobid);
      $stmt= sqlsrv_query( $this->conn, "SELECT [Data] FROM Blobs WHERE Blobid = '{$blobid}'" );
      if ( $stmt == FALSE )
          die( $this->formatErrors( sqlsrv_errors()));
      $blob = '';
      while (sqlsrv_fetch( $stmt)) {
        $blob .= stream_get_contents(sqlsrv_get_field($stmt, 0, SQLSRV_PHPTYPE_STREAM(SQLSRV_ENC_BINARY)));
      }
      sqlsrv_free_stmt( $stmt );
      $stmt = NULL;
    
      if (!empty($blob))
      {
        
        $file = file_save_data($blob, $uri, FILE_EXISTS_REPLACE);
      }
      $blob = NULL;
    }

    //only create media entity if not exists
    if (empty($media_entity))
    {
      $term = \Drupal::service('entity.repository')->loadEntityByUuid('taxonomy_term', strtolower($item->parentid));

      $params = [
        'bundle' => $media,
        'name' => $item->name,
        'uuid' => strtolower($item->id),
        'langcode' => $lang,
        'created' => $this->createdDate($fields),
        'changed' => $this->updatedDate($fields),
        'uid' => $this->createdBy($fields),
        'field_directory' => [$term->id()],
        'field_meta_tags' => serialize([
          'title' => empty($fields[SC_File::Title]->value) ? NULL : mb_strimwidth($fields[SC_File::Title]->value, 0 , 255),
          'description' => empty($fields[SC_File::Description]->value) ? NULL : $fields[SC_File::Description]->value,
          'keywords' => empty($fields[SC_File::Keywords]->value) ? NULL : $fields[SC_File::Keywords]->value,
        ]),
        "field_$media" => [
          'target_id' => $file->id(),
        ],
      ];
      
      if ($media == 'image')
      {
        $params['field_image'] += [
          'alt' => empty($fields[SC_Image::Alt]->value) ? '' : $fields[SC_Image::Alt]->value,
        ];
      }
      
      try {
        $media_entity = Media::create($params);
        $media_entity->save();
        dpm('created: ' . $media_entity->id());
      }
      catch (Throwable $t)
      {
         dpm($t);
      }
    }
    else {
      if ($media_entity->language()->getId() != $lang) { return NULL; }
      $media_entity->set('field_meta_tags', serialize([
          'title' => empty($fields[SC_File::Title]->value) ? NULL : mb_strimwidth($fields[SC_File::Title]->value, 0 , 255),
          'description' => empty($fields[SC_File::Description]->value) ? NULL : $fields[SC_File::Description]->value,
          'keywords' => empty($fields[SC_File::Keywords]->value) ? NULL : $fields[SC_File::Keywords]->value,
        ]));
      if ($media == 'image')
      {
        $media_entity->set('field_image', ['alt' => empty($fields[SC_Image::Alt]->value) ? '' : $fields[SC_Image::Alt]->value, 'target_id' => $media_entity->field_image->first()->target_id]);
      }
      $media_entity->set('changed', $this->updatedDate($fields));
      $media_entity->save();
      dpm('updated: ' . $media_entity->id());
    }
    
    return $media_entity;
  }
   
  /**
   * Import provincial home pages
   * 
   * @param type $lang
   * @param type $update
   */
  public function importProvinces($lang = NULL, $update = FALSE, $id = NULL, $update_settings = FALSE)
  {
    $items = $this->queryObjects('SELECT id, name, templateid, parentid, created, updated  FROM Items '
        . 'WHERE templateId = \'D9A727E8-ED26-456B-A6ED-03E021269208\' and '
        . ($id ? "id = '$id'" : 'id <> \'0C7FBA80-A8E7-47F2-9210-78A947A2938E\'')
        . ' Order By updated asc');
    
    foreach ($items as $item)
    {
      if ($item->name == '__Standard Values') { continue; }
      
      //check if the node exists
      $exists = \Drupal::service('entity.repository')->loadEntityByUuid('group', $item->id);
      
      //setup variables that we will need for new and updated content
      if (empty($exists) || $update)
      {
        $fields = $this->fields($item->id, $lang);
        $langs = $this->getFieldLangs($fields);
        $group = NULL; //reset $node value in case first language is not hit
        
        if (empty($langs)) { continue; }
        
        if ($update_settings) { $settings = $this->parseSettingsData($item->id, NULL, $item->name); }
        
        if ($lang) { 
          $fields += $settings[$lang];
          $fl = &$fields; 
        }
        else { 
          if (!empty($settings[$langs[0]])) { $fields[$langs[0]] += $settings[$langs[0]]; }
          if (!empty($langs[1]) && !empty($settings[$langs[1]])) $fields[$langs[1]] += $settings[$langs[1]];
          $fl = &$fields[$langs[0]]; 
        }
      }
      
      //New node
      if (empty($exists))
      {
        $data = $this->commonGroup($item, $fl, 'provincial', $langs[0]);
        if (!empty($data))
        {
          $group = \Drupal\group\Entity\Group::create($data);
          $group->save();
          dpm('created: ' . $langs[0] . ' ' . $group->id());
        }

        //add translation
        if (!empty($langs[1]))
        {
          $data = $this->commonGroup($item, $fields[$langs[1]], 'provincial', $langs[1]);
          $this->saveGroupTranslation($group, $data, $langs[1]);
        }
      }
      //update node
      elseif ($update) {
        //first translation
        $data = $this->commonGroup($item, $fl, 'provincial', $langs[0]);
        $this->saveGroupTranslation($exists, $data, $langs[0]);

        //second language item
        if (!empty($langs[1]))
        {
          $data = $this->commonGroup($item, $fields[$langs[1]], 'provincial', $langs[1]);
          $this->saveGroupTranslation($exists, $data, $langs[1]);
        }
      } 
    }
  }
  
  /**
   * Import  home page
   * 
   * @param type $lang
   * @param type $update
   */
  public function importHome()
  {
    $items = $this->queryObjects('SELECT id, name, templateid, parentid, created, updated  FROM Items '
        . 'WHERE id = \'110D559F-DEA5-42EA-9C1C-8A5DF7E70EF9\'');
    
    foreach ($items as $item)
    {
      //check if the node exists
      $exists = \Drupal::service('entity.repository')->loadEntityByUuid('group', $item->id);
      
      $fields = $this->fields($item->id);
      $group = NULL; //reset $node value in case first language is not hit
      
      $settings = $this->parseSettingsData($item->id, NULL, $item->name);

      $fields['en'] += $settings['en'];
      $fields['fr'] += $settings['fr'];
      $fl = &$fields['en'];

      //New node
      if (empty($exists))
      {
        $data = $this->commonGroup($item, $fl, 'provincial', 'en');
        $group = \Drupal\group\Entity\Group::create($data);
        $group->save();
        dpm('created: ' . 'en' . ' ' . $group->id());

        $data = $this->commonGroup($item, $fields['fr'], 'provincial', 'fr');
        $this->saveGroupTranslation($group, $data, 'fr');
      }
      //update node
      else {
        //first translation
        $data = $this->commonGroup($item, $fl, 'provincial', 'en');
        $this->saveGroupTranslation($exists, $data, 'en');

        //second language item
        $data = $this->commonGroup($item, $fields['fr'], 'provincial', 'fr');
        $this->saveGroupTranslation($exists, $data, 'fr');
      } 
    }
  }
  
  /**
   * Import chapter home pages
   * 
   * @param type $lang
   * @param type $update
   */
  public function importChapters($lang = NULL, $update = FALSE, $update_settings = FALSE)
  {
    $items = $this->queryObjects('SELECT id, name, templateid, parentid, created, updated  FROM Items WHERE templateId = \'7C09E405-F9CC-439F-BEBC-547EF14C7B36\' Order By updated asc');
    
    foreach ($items as $item)
    {
      if ($item->name == '__Standard Values' || $item->name == '$name') { continue; }
      
      //check if the node exists
      $exists = \Drupal::service('entity.repository')->loadEntityByUuid('group', $item->id);
      
      //setup variables that we will need for new and updated content
      if (empty($exists) || $update)
      {
        $fields = $this->fields($item->id, $lang);
        $langs = $this->getFieldLangs($fields);
        $group = NULL; //reset $node value in case first language is not hit
        
        if (empty($langs)) { continue; }
        
        if ($update_settings) { $settings = $this->parseSettingsData($item->id, NULL, $item->name); }
        
        if ($lang) { 
          if ($update_settings) { $fields += $settings[$lang]; }
          $fl = &$fields; 
        }
        else { 
          if (!empty($settings[$langs[0]])) { $fields[$langs[0]] += $settings[$langs[0]]; }
          if (!empty($langs[1]) && !empty($settings[$langs[1]])) $fields[$langs[1]] += $settings[$langs[1]];
          $fl = &$fields[$langs[0]]; 
        }

        if (!empty($fl[SC_Chapter::NeverPublish]) && $fl[SC_Chapter::NeverPublish]->value == '1') { continue; }
        
        
      }
      
      //New node
      if (empty($exists))
      {
        $data = $this->commonGroup($item, $fl, 'chapter', $langs[0]);
        if (!empty($data))
        {
          $group = \Drupal\group\Entity\Group::create($data);
          $group->save();
          dpm('created: ' . $langs[0] . ' ' . $group->id());
        }

        //add translation
        if (!empty($langs[1]))
        {
          $data = $this->commonGroup($item, $fields[$langs[1]], 'chapter', $langs[1]);
          $this->saveGroupTranslation($group, $data, $langs[1]);
        }
      }
      //update node
      elseif ($update) {
        //first translation
        $data = $this->commonGroup($item, $fl, 'chapter', $langs[0]);
        $this->saveGroupTranslation($exists, $data, $langs[0]);

        //second language item
        if (!empty($langs[1]))
        {
          $data = $this->commonGroup($item, $fields[$langs[1]], 'chapter', $langs[1]);
          $this->saveGroupTranslation($exists, $data, $langs[1]);
        }
      } 

      
      
      $fields = NULL;
      
    }
  }
  
  /**
   * Import hotspots
   * 
   * @param string $lang
   * @param bool $update if updates should also be run. Default to new only
   */
  public function importHotspots($lang = NULL, $update = FALSE, $id = NULL, $page_size = NULL, $page_num = NULL)
  {
    $sql = 'SELECT id, name, templateid, parentid, created, updated  FROM Items WHERE templateId = \'B94C63C2-BD41-4A2D-A65B-7DA1F1EEC14B\' ';
    if ($id) { $sql .= " AND id = '$id' "; }
    $sql .= ' ORDER BY updated asc';
    
    $items = $this->queryObjects($sql, NULL, FALSE, $page_size, $page_num);

    foreach ($items as $item)
    {
      $exists = \Drupal::service('entity.repository')->loadEntityByUuid('node', $item->id);

      //setup variables that we will need for new and updated content
      if (empty($exists) || $update)
      {
        $fields = $this->fields($item->id, $lang);
        $langs = $this->getFieldLangs($fields);
        $node = NULL; //reset $node value in case first language is not hit
        
        if (empty($langs)) { continue; }
        
        if ($lang) { $fl = &$fields; }
        else { $fl = &$fields[$langs[0]]; }
        
        $dir = \Drupal::service('entity.repository')->loadEntityByUuid('taxonomy_term', $item->parentid);
        //TODO: if dir not found
      }
      
      //New node
      if (empty($exists))
      {
        $data = $this->prepareHotspotData($item, $fl, $langs[0], $dir);
        if (!empty($data))
        {
          $node = Node::create($data);
          $node->save();
          dpm('created: ' . $langs[0] . ' ' . $node->id());
        }

        //add translation
        if (!empty($langs[1]))
        {
          $data = $this->prepareHotspotData($item, $fields[$langs[1]], $langs[1], $dir);
          $this->saveTranslation($node, $data, $langs[1]);
        }
      }
      //update node
      elseif ($update) {
        //first translation
        $data = $this->prepareHotspotData($item, $fl, $langs[0], $dir);
        $this->saveTranslation($exists, $data, $langs[0]);

        //second language item
        if (!empty($langs[1]))
        {
          $data = $this->prepareHotspotData($item, $fields[$langs[1]], $langs[1], $dir);
          $this->saveTranslation($exists, $data, $langs[1]);
        }
      } 
    }
  }
  
  /**
   * Import content modules
   * 
   * @param type $lang
   * @param type $update
   * @param type $id
   * @param int $page_size
   * @param int $page_num
   */
  public function importContentModules($lang = NULL, $update = FALSE, $id = NULL, $page_size = NULL, $page_num = NULL)
  {
    $sql = "SELECT id, name, templateid, parentid, created, updated  FROM Items WHERE templateId = 'EDFC3666-D67C-4F6F-A45D-5CBB8B5C2F45' ";
    if ($id) { $sql .= " AND id = '$id' "; }
    $sql .= ' ORDER BY updated asc';
    
    $items = $this->queryObjects($sql, NULL, FALSE, $page_size, $page_num);

    foreach ($items as $item)
    {
      $exists = \Drupal::service('entity.repository')->loadEntityByUuid('node', $item->id);

      //setup variables that we will need for new and updated content
      if (empty($exists) || $update)
      {
        $fields = $this->fields($item->id, $lang);
        $langs = $this->getFieldLangs($fields);
        $node = NULL; //reset $node value in case first language is not hit
        
        if (empty($langs)) { continue; }
        
        if ($lang) { $fl = &$fields; }
        else { $fl = &$fields[$langs[0]]; }
        
        $dir = \Drupal::service('entity.repository')->loadEntityByUuid('taxonomy_term', $item->parentid);
        //TODO: if dir not found
      }

      //New node
      if (empty($exists))
      {
        $data = $this->prepareContentModuleData($item, $fl, $langs[0], $dir);
        if (!empty($data))
        {
          $node = Node::create($data);
          $node->save();
          dpm('created: ' . $langs[0] . ' ' . $node->id());
        }

        //add translation
        if (!empty($langs[1]))
        {
          $data = $this->prepareContentModuleData($item, $fields[$langs[1]], $langs[1], $dir);
          $this->saveTranslation($node, $data, $langs[1]);
        }
      }
      //update node
      elseif ($update) {
        //first translation
        $data = $this->prepareContentModuleData($item, $fl, $langs[0], $dir);
        $this->saveTranslation($exists, $data, $langs[0]);

        //second language item
        if (!empty($langs[1]))
        {
          $data = $this->prepareContentModuleData($item, $fields[$langs[1]], $langs[1], $dir);
          $this->saveTranslation($exists, $data, $langs[1]);
        }
      } 
    }
  }
  
  /**
   * Import landing pages
   * 
   * @param string $lang
   * @param bool $update
   * @param string $id
   */
  public function importLandingPages($lang = NULL, $update = FALSE, $id = NULL, $page_size = NULL, $page_num = NULL)
  {
    $sql = 'SELECT id, name, templateid, parentid, created, updated  FROM Items WHERE templateId = \'F9292D58-1A2C-41EC-AF49-89CE4014F27B\' ';
    if ($id) { $sql .= " AND id = '$id'"; }
    $sql .= " AND Name <> '__Standard Values' order by updated asc";
    $items = $this->queryObjects($sql, NULL, FALSE, $page_size, $page_num);
    
    foreach ($items as $item)
    {
      $exists = \Drupal::service('entity.repository')->loadEntityByUuid('node', $item->id);
      
      //setup variables that we will need for new and updated content
      if (empty($exists) || $update)
      {
        $fields = $this->fields($item->id, $lang);
        $langs = $this->getFieldLangs($fields);
        $node = NULL; //reset $node value in case first language is not hit
        
        if (empty($langs)) { continue; }
        
        if ($lang) { $fl = &$fields; }
        else { $fl = &$fields[$langs[0]]; }
      }
      
      //New node
      if (empty($exists))
      {
        $data = $this->prepareLandingPage($item, $fl, $langs[0]);
        if (!empty($data))
        {
          $node = Node::create($data);
          $node->save();
          dpm('created: ' . $langs[0] . ' ' . $node->id());
        }

        //add translation
        if (!empty($langs[1]))
        {
          $data = $this->prepareLandingPage($item, $fields[$langs[1]], $langs[1]);
          $this->saveTranslation($node, $data, $langs[1]);
        }
      }
      //update node
      elseif ($update) {
        //first translation
        $data = $this->prepareLandingPage($item, $fl, $langs[0]);
        $this->saveTranslation($exists, $data, $langs[0]);

        //second language item
        if (!empty($langs[1]))
        {
          $data = $this->prepareLandingPage($item, $fields[$langs[1]], $langs[1]);
          $this->saveTranslation($exists, $data, $langs[1]);
        }
      } 
    }
  }
  
  /**
   * Import generic pages
   * 
   * @param string $lang
   * @param bool $update
   * @param string $id
   */
  public function importGenericPages($lang = NULL, $update = FALSE, $id = NULL, $page_size = NULL, $page_num = NULL)
  {
    $sql = "SELECT id, name, templateid, parentid, created, updated  FROM Items WHERE templateId = '885B08DC-70D9-442B-90A4-E64C9DA9629A' ";
    if ($id) { $sql .= " AND id = '$id'"; }
    $sql .= " AND Name <> '__Standard Values' order by updated asc";
    $items = $this->queryObjects($sql, NULL, FALSE, $page_size, $page_num);

    foreach ($items as $item)
    {
      $exists = \Drupal::service('entity.repository')->loadEntityByUuid('node', $item->id);
      
      //setup variables that we will need for new and updated content
      if (empty($exists) || $update)
      {
        $fields = $this->fields($item->id, $lang);
        $langs = $this->getFieldLangs($fields);
        $node = NULL; //reset $node value in case first language is not hit
        
        if (empty($langs)) { continue; }
        
        if ($lang) { $fl = &$fields; }
        else { $fl = &$fields[$langs[0]]; }
      }
      
      //New node
      if (empty($exists))
      {
        $data = $this->prepareGenericPage($item, $fl, $langs[0]);
        if (!empty($data))
        {
          $node = Node::create($data);
          $node->save();
          dpm('created: ' . $langs[0] . ' ' . $node->id());
        }

        //add translation
        if (!empty($langs[1]))
        {
          $data = $this->prepareGenericPage($item, $fields[$langs[1]], $langs[1]);
          $this->saveTranslation($node, $data, $langs[1]);
        }
      }
      //update node
      elseif ($update) {
        //first translation
        $data = $this->prepareGenericPage($item, $fl, $langs[0]);
        $this->saveTranslation($exists, $data, $langs[0]);

        //second language item
        if (!empty($langs[1]))
        {
          $data = $this->prepareGenericPage($item, $fields[$langs[1]], $langs[1]);
          $this->saveTranslation($exists, $data, $langs[1]);
        }
      } 
    }
  }
  
  /**
   * Import article pages (regular, wide, no rail)
   * 
   * @param string $lang
   * @param bool $update
   * @param string $id
   */
  public function importArticles($lang = NULL, $update = FALSE, $id = NULL, $page_size = NULL, $page_num = NULL)
  {
    $sql = "SELECT id, name, templateid, parentid, created, updated  FROM Items WHERE templateId in ('0C8E8943-17F6-4C48-8B9C-5BD3E83306F8','D1FE7888-2E7F-44F3-B225-421EF0866878','7C233194-AE90-46C7-A0B5-7474079B4673') ";
    if ($id) { $sql .= " AND id = '$id'"; }
    $sql .= " AND Name <> '__Standard Values' order by updated asc";
    $items = $this->queryObjects($sql, NULL, FALSE, $page_size, $page_num);

    foreach ($items as $item)
    {
      $exists = \Drupal::service('entity.repository')->loadEntityByUuid('node', $item->id);
      
      //setup variables that we will need for new and updated content
      if (empty($exists) || $update)
      {
        $fields = $this->fields($item->id, $lang);
        $langs = $this->getFieldLangs($fields);
        $node = NULL; //reset $node value in case first language is not hit
        if (empty($langs)) { continue; }
        
        if ($lang) { $fl = &$fields; }
        else { $fl = &$fields[$langs[0]]; }
      }
      
      //New node
      if (empty($exists))
      {
        $data = $this->prepareArticle($item, $fl, $langs[0]);
        if (!empty($data))
        {
          $node = Node::create($data);
          $node->save();
          dpm('created: ' . $langs[0] . ' ' . $node->id());
          //add article to group
          //$group = \Drupal::entityTypeManager()->getStorage('group')->load($groupid);
          //$group->addContent($entity, 'group_node:article');
          //$group->save();
        }

        //add translation
        if (!empty($langs[1]))
        {
          $data = $this->prepareArticle($item, $fields[$langs[1]], $langs[1]);
          $this->saveTranslation($node, $data, $langs[1]);
        }
      }
      //update node
      elseif ($update) {
        //first translation
        $data = $this->prepareArticle($item, $fl, $langs[0]);
        $this->saveTranslation($exists, $data, $langs[0]);

        //second language item
        if (!empty($langs[1]))
        {
          $data = $this->prepareArticle($item, $fields[$langs[1]], $langs[1]);
          $this->saveTranslation($exists, $data, $langs[1]);
        }
      } 
    }
  }
  
  /**
   * Import basic pages
   * 
   * @param string $lang
   * @param bool $update
   * @param string $id
   */
  public function importBasicPages($lang = NULL, $update = FALSE, $id = NULL, $page_size = NULL, $page_num = NULL)
  {
    $sql = "SELECT id, name, templateid, parentid, created, updated  FROM Items WHERE templateId = 'ED48391C-3E8D-4629-B6A9-4A5A0AB5BAEB' ";
    if ($id) { $sql .= " AND id = '$id'"; }
    $sql .= " AND Name <> '__Standard Values' order by updated asc";
    $items = $this->queryObjects($sql, NULL, FALSE, $page_size, $page_num);

    foreach ($items as $item)
    {
      $exists = \Drupal::service('entity.repository')->loadEntityByUuid('node', $item->id);
      
      //setup variables that we will need for new and updated content
      if (empty($exists) || $update)
      {
        $fields = $this->fields($item->id, $lang);
        $langs = $this->getFieldLangs($fields);
        $node = NULL; //reset $node value in case first language is not hit
        
        if (empty($langs)) { continue; }
        
        if ($lang) { $fl = &$fields; }
        else { $fl = &$fields[$langs[0]]; }
      }
      
      //New node
      if (empty($exists))
      {
        $data = $this->prepareBasicPage($item, $fl, $langs[0]);
        if (!empty($data))
        {
          $node = Node::create($data);
          $node->save();
          dpm('created: ' . $langs[0] . ' ' . $node->id());
        }

        //add translation
        if (!empty($langs[1]))
        {
          $data = $this->prepareBasicPage($item, $fields[$langs[1]], $langs[1]);
          $this->saveTranslation($node, $data, $langs[1]);
        }
      }
      //update node
      elseif ($update) {
        //first translation
        $data = $this->prepareBasicPage($item, $fl, $langs[0]);
        $this->saveTranslation($exists, $data, $langs[0]);

        //second language item
        if (!empty($langs[1]))
        {
          $data = $this->prepareBasicPage($item, $fields[$langs[1]], $langs[1]);
          $this->saveTranslation($exists, $data, $langs[1]);
        }
      } 
    }
  }
  
  /**
   * Import news
   * 
   * @param string $lang
   * @param bool $update if updates should also be run. Default to new only
   */
  public function importNews($lang = NULL, $update = FALSE, $page_size = NULL, $page_num = NULL)
  {
    $items = $this->queryObjects('SELECT id, name, templateid, parentid, created, updated  FROM Items '
        . 'WHERE templateId = \'F8643F17-8F52-4C4F-84C8-1CB468C744CA\'  order by updated asc', NULL, FALSE, $page_size, $page_num);
    
    foreach ($items as $item)
    {
      $exists = \Drupal::service('entity.repository')->loadEntityByUuid('node', $item->id);
      
      //setup variables that we will need for new and updated content
      if (empty($exists) || $update)
      {
        $fields = $this->fields($item->id, $lang);
        $langs = $this->getFieldLangs($fields);
        $node = NULL; //reset $node value in case first language is not hit
        
        if (empty($langs)) { continue; }
        
        if ($lang) { $fl = &$fields; }
        else { $fl = &$fields[$langs[0]]; }
        
        $dir = \Drupal::service('entity.repository')->loadEntityByUuid('taxonomy_term', $item->parentid);
        //TODO: if dir not found
      }
      
      //New node
      if (empty($exists))
      {
        $data = $this->prepareNewsData($item, $fl, $langs[0], $dir);
        if (!empty($data))
        {
          $node = Node::create($data);
          $node->save();
          dpm('created: ' . $langs[0] . ' ' . $node->id());
        }

        //add translation
        if (!empty($langs[1]))
        {
          $data = $this->prepareNewsData($item, $fields[$langs[1]], $langs[1], $dir);
          $this->saveTranslation($node, $data, $langs[1]);
        }
      }
      //update node
      elseif ($update) {
        //first translation
        $data = $this->prepareNewsData($item, $fl, $langs[0], $dir);
        $this->saveTranslation($exists, $data, $langs[0]);

        //second language item
        if (!empty($langs[1]))
        {
          $data = $this->prepareNewsData($item, $fields[$langs[1]], $langs[1], $dir);
          $this->saveTranslation($exists, $data, $langs[1]);
        }
      } 
    }
  }
  
  /**
   * Import events
   * 
   * @param string $lang
   * @param bool $update if updates should also be run. Default to new only
   */
  public function importEvents($lang = NULL, $update = FALSE, $page_size = NULL, $page_num = NULL)
  { 
    $items = $this->queryObjects('SELECT id, name, templateid, parentid, created, updated  FROM Items WHERE templateId = \'10E27F73-ED51-420B-8AF9-67BB508DDB2B\' order by updated asc', 
        NULL, FALSE, $page_size, $page_num);

    foreach ($items as $item)
    {
      $exists = \Drupal::service('entity.repository')->loadEntityByUuid('node', $item->id);
      
      //setup variables that we will need for new and updated content
      if (empty($exists) || $update)
      {
        $fields = $this->fields($item->id, $lang);
        $langs = $this->getFieldLangs($fields);
        $node = NULL; //reset $node value in case first language is not hit
        
        if (empty($langs)) { continue; }
        
        if ($lang) { $fl = &$fields; }
        else { $fl = &$fields[$langs[0]]; }
        
        $dir = \Drupal::service('entity.repository')->loadEntityByUuid('taxonomy_term', $item->parentid);
        //TODO: if dir not found
      }
      
      //New node
      if (empty($exists))
      {
        $data = $this->prepareEventData($item, $fl, $langs[0], $dir);
        if (!empty($data))
        {
          $node = Node::create($data);
          $node->save();
          dpm('created: ' . $langs[0] . ' ' . $node->id());
        }

        //add translation
        if (!empty($langs[1]))
        {
          $data = $this->prepareEventData($item, $fields[$langs[1]], $langs[1], $dir);
          $this->saveTranslation($node, $data, $langs[1]);
        }
      }
      //update node
      elseif ($update) {
        //first translation
        $data = $this->prepareEventData($item, $fl, $langs[0], $dir);
        $this->saveTranslation($exists, $data, $langs[0]);

        //second language item
        if (!empty($langs[1]))
        {
          $data = $this->prepareEventData($item, $fields[$langs[1]], $langs[1], $dir);
          $this->saveTranslation($exists, $data, $langs[1]);
        }
      } 
    }
  }
  
  /**
   * Import banners
   * 
   * @param type $lang
   * @param type $update
   */
  public function importBanners($lang = NULL, $update = FALSE, $page_size = NULL, $page_num = NULL)
  {
    $items = $this->queryObjects('SELECT id, name, templateid, parentid, created, updated  FROM Items WHERE templateId = \'BFE3828D-E70E-4F73-87FE-BE3FEAFB6DE1\' order by updated asc', 
        NULL, FALSE, $page_size, $page_num);
    
    foreach ($items as $item)
    {
      $exists = \Drupal::service('entity.repository')->loadEntityByUuid('node', $item->id);
      
      //setup variables that we will need for new and updated content
      if (empty($exists) || $update)
      {
        $fields = $this->fields($item->id, $lang);
        $langs = $this->getFieldLangs($fields);
        $node = NULL; //reset $node value in case first language is not hit
        
        if (empty($langs)) { continue; }
        
        if ($lang) { $fl = &$fields; }
        else { $fl = &$fields[$langs[0]]; }
        
        $dir = \Drupal::service('entity.repository')->loadEntityByUuid('taxonomy_term', $item->parentid);
        //TODO: if dir not found
      }
      
      //New node
      if (empty($exists))
      {
        $data = $this->prepareBannerData($item, $fl, $langs[0], $dir);
        if (!empty($data))
        {
          $node = Node::create($data);
          $node->save();
          dpm('created: ' . $langs[0] . ' ' . $node->id());
        }

        //add translation
        if (!empty($langs[1]))
        {
          $data = $this->prepareBannerData($item, $fields[$langs[1]], $langs[1], $dir);
          $this->saveTranslation($node, $data, $langs[1]);
        }
      }
      //update node
      elseif ($update) {
        //first translation
        $data = $this->prepareBannerData($item, $fl, $langs[0], $dir);
        $this->saveTranslation($exists, $data, $langs[0]);

        //second language item
        if (!empty($langs[1]))
        {
          $data = $this->prepareBannerData($item, $fields[$langs[1]], $langs[1], $dir);
          $this->saveTranslation($exists, $data, $langs[1]);
        }
      } 
    }
  }
  
  public function mapToGroup($group_id)
  {
    $group = \Drupal::service('entity.repository')->loadEntityByUuid('group', $group_id);
    if (empty($group)) return;
    
    $q = "WITH 
           cte AS 
              (
              SELECT a.Id
                FROM Items a
                WHERE Id = '{$group_id}'
              UNION ALL
              SELECT a.Id
                FROM items a
                JOIN cte c ON a.parentid = c.id
              )
              SELECT LOWER(id) as id
            FROM cte WHERE id <> '{$group_id}'";
    
    $items = $this->queryObjects($q);
    
    foreach ($items as $item)
    {
      $node = \Drupal::service('entity.repository')->loadEntityByUuid('node', $item);
      if (empty($node)) continue;
      if ($group->getContent(NULL, ['entity_id' => $node->id()])) continue;
      $group->addContent($node, 'group_node:' . $node->getType());
    }
    $group->save();
  }
  
  /**
   * Setup menu for all children of the group
   * 
   * @param string $group_id uuid
   * @param string $menu menu name
   * @return type
   */
  public function setupMenuItems($group_id, $menu)
  {
    $q = "WITH itm AS (
            SELECT a.Id, a.parentid, a.name, cast(ISNULL(s.value, 0) as int) as sort
                FROM Items a
              left join sharedfields s on a.ID = s.ItemId and FieldId = 'BA3F86A2-4A1C-4D78-B63D-91C2779C1B5E'
              ),
           cte AS 
              (
              SELECT a.Id, a.ParentId, a.name, 0 as depth, 0 as sort
                FROM Items a
                WHERE Id = '$group_id'
              UNION ALL
              SELECT a.Id, a.parentid, a.name, depth + 1 as depth, a.sort
                FROM itm a
                JOIN cte c ON a.parentid = c.id

              )
              SELECT LOWER(parentid) as parentid, LOWER(id) as id, name, depth, sort 
            FROM cte WHERE id <> '$group_id' order by depth, sort";
    
    $items = $this->queryObjects($q, 'id');
    $links = [];
    $menu_link_manager = \Drupal::service('plugin.manager.menu.link');
    $nids = $this->getNodeIds(array_keys($items));

    foreach ($items as $item)
    {      
      //find node
      if (empty($nids[$item->id]))
      {
        dpm("No node: " . $item->id);
        continue;
      }
      
      if ($item->depth > 1 && empty($links[$item->parentid]))
      {
        dpm("Missing parent menu item: " . $item->parentid . ' for ' . $item->id);
        continue;
      }
      
      //don't resave existing links - assuming they haven't changed once created
      $link = $menu_link_manager->loadLinksByRoute('entity.node.canonical', array('node' => $nids[$item->id]->nid));
      if (!empty($link))
      {
        $l = reset($link);
        if ($l->getMenuName() == $menu)
        {
          $links[$item->id] = $l->getPluginId();
          continue;
        }
        else {
          dpm("Node " . $nids[$item->id]->nid . " exists in another menu: " . $l->getMenuName());
        }
      }
      
      $parent = empty($links[$item->parentid]) ? NULL : $links[$item->parentid];
      
      if (!empty($nids[$item->id]->en))
      {
        $data = $this->menuLinkData($menu, $nids[$item->id]->en, 'en', $nids[$item->id]->nid, $item->sort, $parent);
        $menu_link = MenuLinkContent::create($data);
        $menu_link->save();
        $links[$item->id] = $menu_link->getPluginId();
        if (!empty($nids[$item->id]->fr))
        {
          $menu_link_fr = $menu_link->addTranslation('fr');
          $menu_link_fr->title = $nids[$item->id]->fr;
          $menu_link_fr->save();
          $menu_link_fr = NULL;
        }
        $menu_link = NULL;
      }
      elseif (!empty($nids[$item->id]->fr))
      {
        $data = $this->menuLinkData($menu, $nids[$item->id]->fr, 'fr', $nids[$item->id]->nid, $item->sort, $parent);
        $menu_link = MenuLinkContent::create($data);
        $menu_link->save();
        $links[$item->id] = $menu_link->getPluginId();
        $menu_link = NULL;
      }
    }
  }
  
  public function parseSettingsData($group_id, $setting_id, $name = NULL)
  {
    if (empty($group_id) || (empty($setting_id) && empty($name))) { return; }
    
    if (empty($setting_id))
    {
      //get the settings based on group name
      $q = "select language, fieldid, value from versionedfields 
          where itemid = (SELECT id FROM items WHERE templateid = 'E47D7EEA-470D-4535-80B1-5DA780D582B5' AND name = '$name')
          and fieldid in ('8550A49E-95DF-4D90-B1EF-042A62925E21', '4062054E-CE86-43DD-91CB-602FDEB08324')";
    }
    else {
      $q = "select language, fieldid, value from versionedfields 
          where itemid = '$setting_id'
          and fieldid in ('8550A49E-95DF-4D90-B1EF-042A62925E21', '4062054E-CE86-43DD-91CB-602FDEB08324')";
    }
    

    $items = $this->queryObjects($q, 'fieldid', TRUE);

    //only update groups if settings_id was provided - means we are parsing the settings directly instead of with the group content
    if (!empty($setting_id))
    {
      $group = \Drupal::service('entity.repository')->loadEntityByUuid('group', $group_id);
      if (!empty($items['en']) && $group->hasTranslation('en'))
      {
        $group_en = $group->getTranslation('en');
        $group_en->set('field_hotspots', empty($items['en'][SC_Home_Common::Hotspots]->value) ? NULL : $this->parseContentList($items['en'][SC_Home_Common::Hotspots]->value));
        $group_en->set('field_top_navigation', empty($items['en'][SC_Home_Common::Navigation]->value) ? NULL : $this->parseContentList($items['en'][SC_Home_Common::Navigation]->value));
        $group_en->save();
      }
      if (!empty($items['fr']) && $group->hasTranslation('fr'))
      {
        $group_fr = $group->getTranslation('fr');
        $group_fr->set('field_hotspots', empty($items['fr'][SC_Home_Common::Hotspots]->value) ? NULL : $this->parseContentList($items['fr'][SC_Home_Common::Hotspots]->value));
        $group_fr->set('field_top_navigation', empty($items['fr'][SC_Home_Common::Navigation]->value) ? NULL : $this->parseContentList($items['fr'][SC_Home_Common::Navigation]->value));
        $group_fr->save();
      }
    }
    
    return $items;
  }
  
  private function menuLinkData($menu, $title, $lang, $id, $weight, $parent = NULL)
  {
    return [
          'bundle' => 'menu_link_content',
          'langcode' => $lang,
          'title' => $title,
          'link' => ['uri' => 'entity:node/' . $id],
          'menu_name' => $menu,
          'weight' => $weight,
          'parent' => $parent,
          'expanded' => FALSE,
        ];
  }
  
  public function deleteLinks($menu)
  {
    $mids = \Drupal::entityQuery('menu_link_content')
      ->condition('menu_name', $menu)
      ->sort('weight')
      ->execute();

    $controller = \Drupal::entityTypeManager()->getStorage('menu_link_content');
    $entities = $controller->loadMultiple($mids);
    $controller->delete($entities);
  }
  
  /**
   * Setup common fields for nodes that are referenced by other content
   * 
   * @param type $item
   * @param type $fields
   * @param type $type
   * @param type $lang
   * @param type $title
   * @param type $body
   * @param string $body_summary
   * @param string $body_format
   * @return type
   */
  public function commonNode(&$item, &$fields, $type, $lang, $title = NULL, $body = NULL, $body_summary = NULL, $body_format = 'full_html')
  {
    $this->current_item = $item;
    $data =  [
      'type' => $type,
      'langcode' => $lang,
      'created' => $this->createdDate($fields),
      'uid' => $this->createdBy($fields),
      'uuid' => strtolower($item->id),
      'changed' => $this->updatedDate($fields),
      'title' => $title ? $title : $item->name,
    ];
    
    //add body field
    if ($body)
    {
      if ($body_format == 'full_html')
      {
        $data['body'] = $this->parseHtml($body);
        if ($body_summary) { $data['body']['summary'] = $body_summary; }
      }
      else
      {
        $data['body'] = [
            'summary' => $body_summary,
            'value' => $body,
            'format' => $body_format,
          ];
      }
    }
    
    return $data;
  }
  
  /**
   * Setup common fields for nodes that are actual pages
   * 
   * @param object $item item
   * @param object $fields item fields
   * @param string $type content type
   * @param string $lang language
   * @param string $body body content
   * @param string $body_summary body summary content
   * @param string $body_format body format
   * @return type
   */
  public function commonPage(&$item, &$fields, $type, $lang, $body = NULL, $body_summary = NULL, $body_format = 'full_html')
  {
    $this->current_item = $item;
    $title = $this->emptyField($fields, SC_Common::Title);
    
    $data =  [
      'type' => $type,
      'langcode' => $lang,
      'created' => $this->createdDate($fields),
      'uuid' => strtolower($item->id),
      'changed' => $this->updatedDate($fields),
      'uid' => $this->createdBy($fields),
      'title' => $title ?: $item->name,
      'field_meta_tags' => serialize([
        'title' => $this->emptyField($fields, SC_Common::MetaTitle),
        'description' => $this->emptyField($fields, SC_Common::Description),
      ]),
      'field_breadcrumb_title' => $this->emptyField($fields, SC_Home_Common::BreadcrumbTitle),
      'field_head_script' => ['value' => $this->emptyField($fields, SC_Home_Common::HeadScript), 'format' => 'javascript'],
      'path' => ['alias' => $this->getPath($item->id), 'pathauto' => 0]
    ];
    
    //add body field
    if ($body)
    {
      if ($body_format == 'full_html')
      {
        $data['body'] = $this->parseHtml($body);
        if ($body_summary) { $data['body']['summary'] = $this->parseHtml($body_summary); }
      }
      else
      {
        $data['body'] = [
            'summary' => $body_summary,
            'value' => $body,
            'format' => $body_format,
          ];
      }
    }
    
    return $data;
  }
  
  /**
   * Setup common fields for node creation
   * 
   * @param type $item
   * @param type $fields
   * @param type $type
   * @param type $lang
   * @return type
   */
  public function commonGroup(&$item, &$fields, $type, $lang)
  {
    $is_chapter = $item->templateid == '7C09E405-F9CC-439F-BEBC-547EF14C7B36';
    
    if ($is_chapter)
    {
      $class = 'Drupal\zaboutme\SC_Chapter';
    }
    elseif ($item->id == '110D559F-DEA5-42EA-9C1C-8A5DF7E70EF9')
    {
      $class = 'Drupal\zaboutme\SC_Homepage';
    }
    else
    {
      $class = 'Drupal\zaboutme\SC_Province';
    }
    
    $this->current_item = $item;
    $banners = [$this->emptyField($fields, $class::Banner1),
        $this->emptyField($fields, $class::Banner2),
        $this->emptyField($fields, SC_Home_Common::Banner3),
        $this->emptyField($fields, SC_Home_Common::Banner4)];
    
    for ($i=0; $i < 4; $i++)
    {
      if (!empty($banners[$i])) { 
        $node = \Drupal::service('entity.repository')->loadEntityByUuid('node', $this->parseId($banners[$i])); 
        $banners[$i] = empty($node) ? NULL : $node->id();
      }
    }    

    $home_html = str_replace('/home', '/' . $lang . '/' . $item->name ,$this->parseHomeLinkHtml($this->emptyField($fields,SC_Home_Common::CurrentLocationHome)));

    $hotspots = $this->emptyField($fields, SC_Home_Common::Hotspots);
    $nav = $this->emptyField($fields, SC_Home_Common::Navigation);
    
    $data = [
      'type' => $type,
      'langcode' => $lang,
      'created' => $this->createdDate($fields),
      'uuid' => strtolower($item->id),
      'label' => $item->name,
      'changed' => $this->updatedDate($fields),
      'uid' => $this->createdBy($fields),
      'path' => ['alias' => '/' . $item->name],
      'field_meta_tags' => serialize([
        'title' => $this->emptyField($fields, SC_Home_Common::MetaTitle),
        'description' => $this->emptyField($fields, SC_Home_Common::Description),
      ]),
      'field_top_navigation' => $this->parseContentList($this->emptyField($fields,SC_Home_Common::Navigation)),
      'field_society_suffix' => $this->emptyField($fields,SC_Home_Common::TitleSuffix),
      'field_banners' => $banners,
      'field_breadcrumb_title' => $this->emptyField($fields, SC_Home_Common::BreadcrumbTitle),
      'field_breaking_news' => $this->parseContentList($this->emptyField($fields, SC_Home_Common::BreakingNews)),
      'field_breaking_news_title' => $this->emptyField($fields, SC_Home_Common::BreakingNewsTitle),
      'field_contact_header_text' => $this->emptyField($fields,SC_Home_Common::ContactHeaderText),
      'field_contact_html' => $this->parseHtml($this->emptyField($fields,SC_Home_Common::ContactHtml)),
      'field_content_modules' => $this->parseContentList($this->emptyField($fields, SC_Home_Common::ContentModules)),
      'field_current_location_home_html' => $home_html,
      'field_current_location_html' => $this->parseHtml($this->emptyField($fields,SC_Home_Common::CurrentLocation)),
      'field_donate_now_html' => $this->parseHtml($this->emptyField($fields,SC_Home_Common::DonateNow)),
      'field_events' => $this->parseContentList($this->emptyField($fields, SC_Home_Common::Events)),
      'field_events_title' => $this->emptyField($fields,SC_Home_Common::EventsTitle),
      'field_follow_html' => $this->parseHtml($this->emptyField($fields,SC_Home_Common::Follow)),
      'field_footer_logos_html' => $this->parseHtml($this->emptyField($fields,SC_Home_Common::FooterLogos)),
      'field_head_script' => ['value' => $this->emptyField($fields, SC_Home_Common::HeadScript), 'format' => 'javascript'],
      //Hotspots are pulled in from a different process
      //,
      'field_quick_links_header_text' => $this->emptyField($fields,SC_Home_Common::QuickLinksHeader),
      'field_quick_links_first' => $this->parseHtml($this->emptyField($fields,SC_Home_Common::QuickLinks)),
      'field_quick_links_second' => $this->parseHtml($this->emptyField($fields,SC_Home_Common::QuickLinks2)),
      'field_quick_links_third' => $this->parseHtml($this->emptyField($fields,SC_Home_Common::QuickLinks3)),
      'field_ua_code' => substr($this->emptyField($fields,SC_Home_Common::UACode), 0, 20),
    ];
    
    if (!empty($hotspots))
    {
      $data['field_hotspots'] = $this->parseContentList($hotspots);
    }
    if (!empty($nav))
    {
      $data['field_top_navigation'] = $this->parseContentList($nav);
    }
       
    return $data;
  }
  
  /**
   * Set fields for update. Create node related fields are removed
   * 
   * @param type $node
   * @param array $data 
   */
  private function updateNodeFields(&$node, &$data)
  {
    $exclude = ['type','created','uuid','langcode'];
    foreach($data as $key => $value)
    {
      if (!in_array($key, $exclude)) { $node->set($key, $value); }
    }
    
    return $node;
  }
  
  /**
   * Fields for landing page
   * 
   * @param object $item
   * @param object $fields
   * @param string $lang
   * @param bool $new if this is a new node
   * @return type
   */
  private function prepareLandingPage(&$item, &$fields, $lang)
  {
    $data = $this->commonPage($item, $fields, 'landing_page', $lang, $this->emptyField($fields, SC_LandingPage::Content));
    $data += [
      'field_breaking_news' => $this->parseContentList($this->emptyField($fields, SC_Common::BreakingNews)),
      'field_breaking_news_title' => $this->emptyField($fields, SC_Common::BreakingNewsTitle),
      'field_content_modules' => $this->parseContentList($this->emptyField($fields, SC_LandingPage::ContentModules)),
      'field_events' => $this->parseContentList($this->emptyField($fields, SC_Common::Events)),
      'field_events_title' => $this->emptyField($fields,SC_Common::EventsTitle),
      'field_hotspots' => $this->parseContentList($this->emptyField($fields, SC_LandingPage::Hotspots)),
      'field_ua_code' => substr($this->emptyField($fields, SC_Common::UACode), 0, 20),
      'field_source' => $this->emptyField($fields, SC_Common::Source),
      'field_sitecore_parent' => $item->parentid,
      'field_sitecore_name' => $item->name,
      'field_display_name' => $this->emptyField($fields,SC_Common::DisplayName),
      'publish_on' => empty($fields[SC_Common::Publish]->value) ? NULL : strtotime($fields[SC_Common::Publish]->value),
      'unpublish_on' => empty($fields[SC_Common::Unpublish]->value) ? NULL : strtotime($fields[SC_Common::Unpublish]->value)
    ];
    
    return $data;
  }
  
  /**
   * Fields for article
   * 
   * @param object $item
   * @param object $fields
   * @param string $lang
   * @param bool $new if this is a new node
   * @return type
   */
  private function prepareArticle(&$item, &$fields, $lang)
  {
    $ids = NULL;
    $data = [];

    switch ($item->templateid)
    {
      case '0C8E8943-17F6-4C48-8B9C-5BD3E83306F8':
        $ids = 'Drupal\zaboutme\SC_Article';
        $data['field_article_layout'] = '1';
        break;
      case 'D1FE7888-2E7F-44F3-B225-421EF0866878':
        $ids = 'Drupal\zaboutme\SC_ArticleWide';
        $data['field_include_donate'] = $this->emptyField($fields, SC_ArticleWide::IncludeDonate, 0);
        $data['field_article_layout'] = '2';
        break;
      case '7C233194-AE90-46C7-A0B5-7474079B4673':
        $ids = 'Drupal\zaboutme\SC_ArticleNoRail';
        $data['field_article_layout'] = '3';
        break;
    }
    
    $data += $this->commonPage($item, $fields, 'article', $lang, $this->emptyField($fields, $ids::Content));
    $hotspots = $this->emptyField($fields, SC_Common::Hotspots);
    $data += [
      'field_hotspots' => empty($hotspots) || $hotspots == SC_Common::EmptyHotspot ? NULL : $this->parseContentList($hotspots),
      'field_ua_code' => substr($this->emptyField($fields, SC_Common::UACode), 0, 20),
      'field_source' => $this->emptyField($fields, SC_Common::Source),
      'field_sitecore_parent' => $item->parentid,
      'field_sitecore_name' => $item->name,
      'field_display_name' => $this->emptyField($fields,SC_Common::DisplayName),
      'field_temp_video' => $this->emptyField($fields,$ids::Video),
      'field_tags' => $this->parseTags($this->emptyField($fields, $ids::Tags)),
      'field_hide_hotspots' => $hotspots == SC_Common::EmptyHotspot ? 1 : 0,
      'field_hide_title' => empty($this->emptyField($fields, SC_Common::Title)) ? TRUE : FALSE,
      'publish_on' => empty($fields[SC_Common::Publish]->value) ? NULL : strtotime($fields[SC_Common::Publish]->value),
      'unpublish_on' => empty($fields[SC_Common::Unpublish]->value) ? NULL : strtotime($fields[SC_Common::Unpublish]->value)
    ];
    
    //Image
    if (!empty($fields[$ids::Image]->value))
    {
      $image = $this->processImage($fields[$ids::Image]->value);
      if (!empty($image['mediaid']))
      {
        $media = \Drupal::service('entity.repository')->loadEntityByUuid('media', $image['mediaid']);
        if (empty($media))
        {
          $media = $this->importMedia('image', NULL, $lang, $image['mediaid'], 'image');
        }
        if (!empty($media)) $data['field_media_image'] = [$media->id()];
      }
    }
    
    return $data;
  }
  
  /**
   * Fields for basic Page from contact us temaplte in sitecore
   * 
   * @param object $item
   * @param object $fields
   * @param string $lang
   * @param bool $new if this is a new node
   * @return type
   */
  private function prepareBasicPage(&$item, &$fields, $lang)
  {
    $data = $this->commonPage($item, $fields, 'page', $lang, $this->emptyField($fields, SC_BasicPage::Content));
    $hotspots = $this->emptyField($fields, SC_BasicPage::Hotspots);
    
    $data += [
      'field_ua_code' => substr($this->emptyField($fields, SC_Common::UACode), 0, 20),
      'field_source' => $this->emptyField($fields, SC_Common::Source),
      'field_sitecore_parent' => $item->parentid,
      'field_sitecore_name' => $item->name,
      'field_display_name' => $this->emptyField($fields,SC_Common::DisplayName),
      'field_hotspots' => empty($hotspots) || $hotspots == SC_Common::EmptyHotspot ? NULL : $this->parseContentList($hotspots),
      'field_hide_hotspots' => $hotspots == SC_Common::EmptyHotspot ? 1 : 0,
      'field_hide_donate' => $this->emptyField($fields, SC_GenericPage::HideDonate),
      'publish_on' => empty($fields[SC_Common::Publish]->value) ? NULL : strtotime($fields[SC_Common::Publish]->value),
      'unpublish_on' => empty($fields[SC_Common::Unpublish]->value) ? NULL : strtotime($fields[SC_Common::Unpublish]->value)
    ];
    
    return $data;
  }
  
  /**
   * Fields for basic Page from Generic
   * 
   * @param object $item
   * @param object $fields
   * @param string $lang
   * @param bool $new if this is a new node
   * @return type
   */
  private function prepareGenericPage(&$item, &$fields, $lang)
  {
    $data = $this->commonPage($item, $fields, 'page', $lang, $this->emptyField($fields, SC_GenericPage::Content));
    $hotspots = $this->emptyField($fields, SC_GenericPage::Hotspots);
    
    $data += [
      'field_ua_code' => substr($this->emptyField($fields, SC_Common::UACode), 0, 20),
      'field_source' => $this->emptyField($fields, SC_Common::Source),
      'field_sitecore_parent' => $item->parentid,
      'field_sitecore_name' => $item->name,
      'field_display_name' => $this->emptyField($fields,SC_Common::DisplayName),
      'field_hotspots' => empty($hotspots) || $hotspots == SC_Common::EmptyHotspot ? NULL : $this->parseContentList($hotspots),
      'field_hide_hotspots' => $hotspots == SC_Common::EmptyHotspot ? 1 : 0,
      'field_hide_donate' => $this->emptyField($fields, SC_GenericPage::HideDonate),
      'publish_on' => empty($fields[SC_Common::Publish]->value) ? NULL : strtotime($fields[SC_Common::Publish]->value),
      'unpublish_on' => empty($fields[SC_Common::Unpublish]->value) ? NULL : strtotime($fields[SC_Common::Unpublish]->value)
    ];
    
    return $data;
  }
    
  /**
   * Parse sitecore link tag
   * 
   * @param string $link
   * @param string $text link text
   * @return string drupalized link
   */
  public function processLink($link, $text = NULL)
  {
    preg_match_all('/\s([^=]+="[^"]*")/', $link, $matches, PREG_PATTERN_ORDER);
    //Then split on on the first = sign
    $props = array();
    foreach ($matches[0] as $match)
    {
        $e = explode('=', $match, 2);
        $props[trim($e[0])] = substr($e[1], 1, -1);
    }
    
    if (!empty($props['linktype']))
    {
      //do some extra cleanup on some of the types.
      switch ($props['linktype'])
      {
        case 'external':
          $props['field'] = ['field_link' => ['uri' => htmlspecialchars_decode($props['url']), 'title' => $text, 'options' => ['target' => '_blank']]];
          break;
        case 'internal':
          //get node by the id field
          if (!empty($props['id']))
          {
            $props['id'] = $this->parseId($props['id']);
            //TODO: generate proper url to node
            $node = \Drupal::service('entity.repository')->loadEntityByUuid('node', $props['id']);
            if ($node)
            {
              $props['url'] = 'entity:node/' . $node->id();
            }
            else {
              $props['url'] = 'http://alzheimer.ca/broken-' . $props['id'];
              $this->missing[] = 'Missing node link: ' . $props['id'] . ' in  ' . $this->current_item->id;
            }
            $props['field'] = ['field_link' => ['uri' => $props['url'], 'title' => $text]];
          }
          //create the link to the node. Also grab the text property (the alt title of the link)
          break;
        case 'media':
          if (!empty($props['id']))
          {
            $props['id'] = $this->parseId($props['id']);
            $media = \Drupal::service('entity.repository')->loadEntityByUuid('media', $props['id']);
            $props['url'] = empty($media) ? NULL : $media->id();
            $props['field'] = ['field_file_link' => [$props['url']]];
          }
          break;
      }
    }
    
    return $props;
  }
  
  public function convertLink($link=null)
  {
    $link = preg_replace_callback('/~\/link\.aspx\?_id=([^\&]*)[^"]*/', array($this, 'getNodeLink'), $link);
  }
  
  /**
   * Parse sitecore image tag
   * 
   * @param string $img
   * @return string drupalized image
   */
  public function processImage($img)
  {
    preg_match_all('/\s([^=]+="[^"]*")/', $img, $matches, PREG_PATTERN_ORDER);
    //Then split on on the first = sign
    $props = array();
    foreach ($matches[0] as $match)
    {
        $e = explode('=', $match, 2);
        $props[trim($e[0])] = substr($e[1], 1, -1);
    }
    
    //remove braces from the media id
    if (!empty($props['mediaid'])) {
      $props['mediaid'] = substr($props['mediaid'], 1, -1);
    }
    
    return $props;
  }
  
  /**
   * Delete all nodes of a content type
   * 
   * @param string $type
   */
  public function deleteNodes($type)
  {
    $query = \Drupal::entityQuery('node');
    $query->condition('type', $type);
    $entity_ids = $query->execute();

    $storage_handler = \Drupal::entityTypeManager()->getStorage('node');
    $entities = $storage_handler->loadMultiple($entity_ids);
    $storage_handler->delete($entities);
  }
  
  /**
   * 
   * @param type $value
   * @param bool $create_inactive if an inactive account sould be created to maintain references
   * @return type
   */
  public function userID($value, $create_inactive = false)
  {
    $nameArr = (explode('\\', $value));
    $name = end($nameArr);

    if (empty($this->users[$name]))
    {
      $users = \Drupal::entityTypeManager()->getStorage('user')
      ->loadByProperties(['name' => $name]);
      $user = reset($users);
      if ($user) {
        $this->users[$name] = $user->id();
      }
      elseif ($create_inactive)
      {
        $user = User::create();
        //Mandatory settings
        $user->setPassword("123XYZ");
        $user->enforceIsNew();
        $user->setEmail($name . '@noemail.com');
        $user->setUsername($name);
        $user->block();
        $user->set('field_comment', "Did not exist in Sitecore. Account created for content references.");
        $res = $user->save();
        $this->users[$name] = $user->id();
      }
    }
    
    return empty($this->users[$name]) ? 1 : $this->users[$name];
  }
  
  public function importUsers()
  {
    $users = $this->queryObjects('SELECT s.UserId, IsApproved, LoweredEmail, CreateDate, LastLoginDate, Comment, LoweredUsername
      FROM [ascSitecore_Core].[dbo].[aspnet_Membership] m inner join [ascSitecore_Core].[dbo].[aspnet_Users] s on m.userid = s.userid
      where IsApproved = 0 and loweredemail <> \'\'');
    
    foreach ($users as $u)
    {
      // Create user object.
      $user = User::create();
      $user->set('created', strtotime($u->CreateDate));
      //Mandatory settings
      $user->setPassword("123XYZ");
      $user->enforceIsNew();
      $user->setEmail($u->LoweredEmail);
      $user->set("init", $u->LoweredEmail);
      $nameArr = explode('\\', $u->LoweredUsername);
      $name = end($nameArr);
      $user->setUsername($name);
      $user->setLastLoginTime(strtotime($u->LastLoginDate));
      $user->setLastAccessTime(strtotime($u->LastLoginDate));
      $u->IsApproved ? $user->activate() :  $user->block();
      $user->set('field_comment', $u->Comment);
      $user->set('uuid', strtolower($u->UserId));
      $user->save();
    }
  }
  
  /**
   * Change database
   * 
   * @param type $name
   * @return type
   */
  public function database($name)
  {
    return sqlsrv_query($this->conn, "USE " . $name);
  }
  
  /**
   * Connect to the db
   * 
   * @return boolean
   */
  private function connect()
  {
    /*    
    $db = Database::getConnectionInfo('master');
    $info = $db['default'];
    $serverName = $info['host'];
    $connectionOptions = array(
        "Database" => $info['database'],
        "Uid" => $info['username'],
        "PWD" => $info['password'],
        'ReturnDatesAsStrings'=>true,
    );
     * 
     */
    $serverName = '209.15.237.142';
    $connectionOptions = array(
        "Database" => 'ascSitecore_web',
        "Uid" => 'perceptible',
        "PWD" => 'Tti2009',
        'ReturnDatesAsStrings'=>true,
    );

    //Establishes the connection
    $this->conn = sqlsrv_connect( $serverName, $connectionOptions );
    
    if( $this->conn === false ) {
      die( $this->formatErrors( sqlsrv_errors()));
      return false;
    }

    return true;
  }
  
  private function formatErrors( $errors )  
  {  
      /* Display errors. */  
      echo "Error information: <br/>";  

      foreach ( $errors as $error )  
      {  
          echo "SQLSTATE: ".$error['SQLSTATE']."<br/>";  
          echo "Code: ".$error['code']."<br/>";  
          echo "Message: ".$error['message']."<br/>";  
      }  
  } 
  
  /**
   * Get created by user. Use updated by if created does not exist
   * 
   * @param type $fields
   * @param type $create
   * @return type
   */
  private function createdBy(&$fields, $create = TRUE)
  {
    if (!empty($fields[SC_Common::CreatedBy]->value))
    {
      $user = $fields[SC_Common::CreatedBy]->value;
    }
    elseif (!empty($fields[SC_Common::UpdatedBy]->value))
    {
      $user = $fields[SC_Common::UpdatedBy]->value;
    }
    else {
      $user = 'admin';
    }
    
    return $this->userID($user, $create);
  }
  
  /**
   * Get updated by user. Use created by if updated does not exist
   * 
   * @param type $fields
   * @param type $create
   * @return type
   */
  private function updatedBy(&$fields, $create = TRUE)
  {
    if (!empty($fields[SC_Common::UpdatedBy]->value))
    {
      $user = $fields[SC_Common::UpdatedBy]->value;
    }
    elseif (!empty($fields[SC_Common::CreatedBy]->value))
    {
      $user = $fields[SC_Common::CreatedBy]->value;
    }
    else {
      $user = 'admin';
    }
    
    return $this->userID($user, $create);
  }
  
  /**
   * Get created date. Use updated by if created does not exist
   * 
   * @param type $fields
   * @param type $create
   * @return type
   */
  private function createdDate(&$fields)
  {
    if (!empty($fields[SC_Common::Created]->value))
    {
      $time = strtotime(substr($fields[SC_Common::Created]->value, 0, 15));
    }
    elseif (!empty($fields[SC_Common::Updated]->value))
    {
      $time = strtotime(substr($fields[SC_Common::Updated]->value, 0, 15));
    }
    else {
      $time = time();
    }
    
    return $time;
  }
  
  /**
   * Get updated date. Use created by if updated does not exist
   * 
   * @param type $fields
   * @param type $create
   * @return type
   */
  private function updatedDate(&$fields)
  {
    if (!empty($fields[SC_Common::Updated]->value))
    {
      $time = strtotime(substr($fields[SC_Common::Updated]->value, 0, 15));
    }
    elseif (!empty($fields[SC_Common::Created]->value))
    {
      $time = strtotime(substr($fields[SC_Common::Created]->value, 0, 15));
    }
    else {
      $time = time();
    }
    
    return $time;
  }
  
  /**
   * Rerturn value  for given key or null if key does not exist in the fields
   * @param type $fields
   * @param type $key
   * @return type
   */
  private function emptyField(&$fields, $key, $default = NULL)
  {
    return empty($fields[$key]->value) ? $default: $fields[$key]->value;
  }
  
  /**
   * Parse images and links in html content
   * 
   * @param type $html
   * @param type $format
   * @return type
   */
  public function parseHtml($html, $format = 'full_html')
  {
    //replace images with media entities
    //$html = preg_replace_callback('/<img .*\/>/', array($this, 'getNodeImage'), $html);
    $html = preg_replace_callback('/~\/media\/([^\.]*)\.ashx/', array($this, 'getMediaLink'), $html);
    $html = preg_replace_callback('/href="~\/link\.aspx\?_id=([^\&]*)[^"]*"/', array($this, 'getNodeLink'), $html);
    //TODO replace links
    return ['value' => $html, 'format' => $format];
  }
  
  /**
   * Parse images and links in html content
   * 
   * @param type $html
   * @param type $format
   * @return type
   */
  public function parseHomeLinkHtml($html, $format = 'full_html')
  {
    //replace images with media entities
    //$html = preg_replace_callback('/<img .*\/>/', array($this, 'getNodeImage'), $html);
    $html = preg_replace_callback('/~\/media\/([^\.]*)\.ashx/', array($this, 'getMediaLink'), $html);
    $html = preg_replace_callback('/href="~\/link\.aspx\?_id=([^\&]*)[^"]*"/', array($this, 'getHomeLink'), $html);
    //TODO replace links
    return ['value' => $html, 'format' => $format];
  }
  
  public function getPath($id)
  {
    $q = "WITH cte AS 
      (
      SELECT a.id, a.ParentId, a.name
      FROM Items a
      WHERE Id = '$id'
      UNION ALL
      SELECT a.id, a.ParentId, a.name
      FROM Items a
      JOIN cte c ON a.id = c.parentid
      )
      SELECT name FROM cte WHERE parentid <> '11111111-1111-1111-1111-111111111111' and id <> '11111111-1111-1111-1111-111111111111'";
    $res = $this->queryArrayField($q);
    $res = array_reverse($res);
    if (in_array($res[0], ['chapters-on','chapters-qc'])) { unset($res[0]); }
    return "/" . implode('/', $res);
  }
  
  private function getEntityImage($matches)
  {
    $data = $this->processLink($matches[0]);
    if (substr($data['src'], 0, 7) == '~/media')
    {
      $media = $this->getMediaEntity($this->formatUuid(substr($data['src'], 8, 32)));
      return $media;
    }
  }
  
  private function getMediaLink($matches)
  {
    $id = $this->formatUuid($matches[1]);
    $media_entity = \Drupal::service('entity.repository')->loadEntityByUuid('media', $id);
    
    if (!empty($media_entity->field_image->entity))
    {
      $url = file_url_transform_relative($media_entity->field_image->entity->url());
      
    }
    elseif (!empty($media_entity->field_document->entity))
    {
      $url = file_url_transform_relative($media_entity->field_document->entity->url());
      
    }
    else {
      $this->missing[] = "Missing media: " . $id . " on " . $this->current_item->id;
      $url =  $matches[0];
    }
    
    return $url;
  }
  
  private function getNodeLink($matches)
  {
    $result = '';
    $id = $this->formatUuid($matches[1]);
    $node = \Drupal::service('entity.repository')->loadEntityByUuid('node', $id);
    if ($node)
    {
      $result = ' data-entity-substitution="canonical" data-entity-type="node" data-entity-uuid="' . $id . '" href="/node/' . $node->id() . '"';
    }
    else {
      $result = $matches[0];
      $this->missing[] = "Missing node link: " . $id . " on " . $this->current_item->id;
    }
    return $result;
  }
  
  private function getHomeLink($matches)
  {
    return ' href="/home"';
  }
  
  private function getMediaEntity($id)
  {
    return '<drupal-entity data-embed-button="media" '
    . 'data-entity-embed-display="entity_reference:media_thumbnail" '
        . 'data-entity-embed-display-settings="{&quot;image_style&quot;:&quot;&quot;,&quot;image_link&quot;:&quot;&quot;}" '
        . 'data-entity-type="media" '
        . 'data-entity-uuid="' . $id . '">'
        . '</drupal-entity>';
  }
  
  private function parseContentList($data)
  {
    $items = explode("|", $data);
    $nids = [];
    foreach ($items as $item)
    {
      $node = \Drupal::service('entity.repository')->loadEntityByUuid('node', substr($item, 1,-1));
      if (!empty($node))
      {
        $nids[] = $node->id();
      }
      else {
        dpm($item);
      }
    }
    
    return $nids;
  }
  
  /**
   * Parse tags and return array of terms
   * 
   * @param type $data
   * @return type
   */
  private function parseTags($data)
  {
    $items = explode("|", $data);
    $tids = [];
    foreach ($items as $item)
    {
      $term = \Drupal::service('entity.repository')->loadEntityByUuid('taxonomy_term', substr($item, 1,-1));
      if (!empty($term))
      {
        $tids[] = $term->id();
      }
      else {
        dpm($item);
      }
    }
    
    return $tids;
  }
  
  private function formatUuid($str)
  {
    $parts = [substr($str,0,8), substr($str,8,4), substr($str,12,4), substr($str,16,4), substr($str,20,12)];
    return strtolower(implode($parts, '-'));
  }
  
  /**
   * Get the languages available in order of created
   * 
   * @param type $fields
   * @return string
   */
  private function getFieldLangs(&$fields)
  {
    $langs = [];
    //check english first
    if (!empty($fields['en'][SC_Common::Created]->value)) { 
      //make english the primary language first
      $langs[] = 'en';
    }
    //check french
    if (!empty($fields['fr'][SC_Common::Created]->value)) { 
      //append french if no english or french created after english
      if (empty($langs) || ($fields['fr'][SC_Common::Created]->value > $fields['en'][SC_Common::Created]->value))
      {
        $langs[] = 'fr';
      }
      else {
        array_unshift($langs, 'fr');
      }
    }
    
    return $langs;
  }
  
  /**
   * Return shared and versioned fields for item id
   * 
   * @param string $id item id
   * @param string $lang language to select. If not provided then will retrieve all available lanaguages for the item
   * @return type
   */
  public function fields($id, $lang = NULL)
  {
    //get source from the master database...it's empty in the web database
    //exclude __Source field from versioned fields and get it from the master database instead
    if ($lang)
    {
      return $this->queryObjects("SELECT itemid, version, fieldid, value, created, updated FROM VersionedFields WHERE ItemId = '{$id}' and language = '{$lang}' and value <> '' and fieldid <> '1B86697D-60CA-4D80-83FB-7555A2E6CE1C'"
      . " UNION SELECT itemid, '' as version, fieldid, value, created, updated FROM SharedFields where itemid = '{$id}' and value <> ''"
      . " UNION SELECT itemid, '' as version, fieldid, value, created, updated FROM UnversionedFields where itemid = '{$id}' and value <> ''"
      . " UNION SELECT itemid, version, fieldid, value, created, updated FROM ascsitecore_master.dbo.VersionedFields WHERE ItemId = '{$id}' and language = '{$lang}' and fieldid = '1B86697D-60CA-4D80-83FB-7555A2E6CE1C'", 'fieldid');
    }
    else {
      return $this->queryObjects("SELECT itemid, language, version, fieldid, value, created, updated FROM VersionedFields WHERE ItemId = '{$id}' and fieldid <> '1B86697D-60CA-4D80-83FB-7555A2E6CE1C' and value <> ''"
      . " UNION SELECT itemid, '' as language, '' as version, fieldid, value, created, updated FROM SharedFields where itemid = '{$id}' and value <> ''"
      . " UNION SELECT itemid, '' as language, '' as version, fieldid, value, created, updated FROM UnversionedFields where itemid = '{$id}' and value <> ''"
      . " UNION SELECT itemid, language, version, fieldid, value, created, updated FROM ascsitecore_master.dbo.VersionedFields WHERE ItemId = '{$id}' and fieldid = '1B86697D-60CA-4D80-83FB-7555A2E6CE1C'", 'fieldid', TRUE);
    }
  }
  
  /**
   * Prepare data for a content module node
   * 
   * @param type $item
   * @param type $fields
   * @param type $lang
   * @param type $dir
   * @return type
   */
  public function prepareContentModuleData(&$item, &$fields, $lang, $dir)
  {
    $summary = $this->emptyField($fields, SC_ContentModules::IntroText);
    $body = $this->emptyField($fields, SC_ContentModules::Html);
    $data = $this->commonNode($item, $fields, 'content_module', $lang, NULL, $body, $summary);
    $data['field_directory'] = [$dir];
    $data['field_title'] = ['value' => $this->emptyField($fields, SC_ContentModules::Title), 'format' => 'formatted_text'];

    //Image
    if (!empty($fields[SC_ContentModules::Image]->value))
    {
      $image = $this->processImage($fields[SC_ContentModules::Image]->value);
      if (!empty($image['mediaid']))
      {
        $media = \Drupal::service('entity.repository')->loadEntityByUuid('media', $image['mediaid']);
        if (empty($media))
        {
          $media = $this->importMedia('image', NULL, $lang, $image['mediaid'], 'image');
        }
        if (!empty($media)) $data['field_media_image'] = [$media->id()];
      }
    }
    
    if (!empty($fields[SC_ContentModules::Link]->value))
    {
      $link = $this->processLink($fields[SC_ContentModules::Link]->value);
      if (!empty($link['field'])) { $data += $link['field']; }  
    }

    return $data;
  }
  
  /**
   * Prepare data for a hotspot node
   * 
   * @param type $item
   * @param type $fields
   * @param type $lang
   * @param type $dir
   * @return array
   */
  public function prepareHotspotData(&$item, &$fields, $lang, $dir)
  {
    //stop if we don't have a link and title/text
    if (!empty($fields[SC_Hotspots::Html]->value))
    {
      $data = $this->commonNode($item, $fields, 'hotspot', $lang, NULL, $fields[SC_Hotspots::Html]->value);
      $data += ['field_directory' => [$dir]];

      return $data;
    }
    
    return NULL;
  }
  
  /**
   * Prepare data for a news node
   * 
   * @param type $item
   * @param type $fields
   * @param type $lang
   * @param type $dir
   * @return type
   */
  public function prepareNewsData(&$item, &$fields, $lang, $dir)
  {
    //stop if we don't have a link and title/text
    if (!empty($fields[SC_News::Link]->value))
    {
      $data = $this->commonNode($item, $fields, 'news', $lang, NULL, $this->emptyField($fields, SC_News::Text), NULL, 'plain_text');
      $data += ['field_directory' => [$dir]];

      $link = $this->processLink($fields[SC_News::Link]->value);
      if (!empty($link['field'])) { $data += $link['field']; }

      return $data;
    }
    
    return NULL;
  }
  
  /**
   * Prepare data for an event node
   * 
   * @param type $item
   * @param type $fields
   * @param type $lang
   * @param type $dir
   * @param type $new
   * @return type
   */
  public function prepareEventData(&$item, &$fields, $lang, $dir, $new = TRUE)
  {
    //stop if we don't have a link and title/text
    if (!empty($fields[SC_Event::Text]->value) && !empty($fields[SC_Event::Link]->value))
    {
      $data = $this->commonNode($item, $fields, 'event', $lang, NULL, $this->emptyField($fields, SC_Event::Text), NULL, 'plain_text');
      $data += ['field_directory' => [$dir]];

      $link = $this->processLink($fields[SC_Event::Link]->value);
      if (!empty($link['field'])) { $data += $link['field']; }

      return $data;
    }
    
    return NULL;
  }
  
  /**
   * Prepare data for a banner node
   * 
   * @param type $item
   * @param type $fields
   * @param type $lang
   * @param type $dir
   * @param type $new
   * @return type
   */
  public function prepareBannerData(&$item, &$fields, $lang, $dir, $new = TRUE)
  {
    //stop if we don't have a link and title/text
    if (!empty($fields[SC_Banner::Image]->value))
    {
      $data = $this->commonNode($item, $fields, 'banner', $lang);
      $data += ['field_directory' => [$dir]];
      
      //image
      $image = $this->processImage($fields[SC_Banner::Image]->value);
      if (!empty($image['mediaid']))
      {
        $media = \Drupal::service('entity.repository')->loadEntityByUuid('media', $image['mediaid']);
        if (empty($media))
        {
          $media = $this->importMedia('image', NULL, $lang, $image['mediaid']);
        }
        if (!empty($media)) $data['field_media_image'] = [$media->id()];
      }

      //link
      $link = $this->processLink($fields[SC_Banner::Link]->value, '');
      if (!empty($link['field'])) { $data += $link['field']; }

      return $data;
    }
    
    return NULL;
  }
  
  /**
   * Create or update a translation
   * 
   * @param object $node root node
   * @param array $data node data
   * @param string $lang translation language
   */
  public function saveTranslation(&$node, &$data, $lang)
  {
    //only process if we have data
    if ($data)
    {
      //if we have a node then we are dealing with translations
      if ($node)
      {
        //if there is a translation
        if ($node->hasTranslation($lang))
        {
          $trans = $node->getTranslation($lang);
          $this->updateNodeFields($trans, $data);
          $trans->save();
          dpm('updated: ' . $lang . ' ' . $trans->id());
        }
        //there is no translation so create new
        else {
          $trans = $node->addTranslation($lang, $data);
          $trans->save();
          dpm('created translation: ' . $lang . ' ' . $trans->id());
        }
      }
      //if no node then create it
      else {
        $node = Node::create($data);
        $node->save();
        dpm('created (instead of trans): ' . $lang . ' ' . $node->id());
      }
      
      return TRUE;
    }
    
    return NULL;
  }
  
  /**
   * Create or update a translation for a group
   * 
   * @param object $group root group
   * @param array $data node data
   * @param string $lang translation language
   */
  public function saveGroupTranslation(&$group, &$data, $lang)
  {
    //only process if we have data
    if ($data)
    {
      //if we have a node then we are dealing with translations
      if ($group)
      {
        //if there is a translation
        if ($group->hasTranslation($lang))
        {
          $trans = $group->getTranslation($lang);
          $this->updateNodeFields($trans, $data);
          $trans->save();
          dpm('updated: ' . $lang . ' ' . $trans->id());
        }
        //there is no translation so create new
        else {
          $trans = $group->addTranslation($lang, $data);
          $trans->save();
          dpm('created translation: ' . $lang . ' ' . $trans->id());
        }
      }
      //if no node then create it
      else {
        $group = \Drupal\group\Entity\Group::create($data);
        $group->save();
        dpm('created (instead of trans): ' . $lang . ' ' . $group->id());
      }
      
      return TRUE;
    }
    
    return NULL;
  }
  
  /**
   * Parse id value to get uuid with braces 
   * @param type $id
   * @return type
   */
  private function parseId($id)
  {
    return substr($id, 0, 1) == '{' ? substr($id, 1, -1) : $id;
  }
  
  public function getNodeIds($uuid)
  {
    $result = db_query("SELECT n.nid, n.uuid, fen.title as en, ffr.title as fr
      FROM {node} n 
        LEFT JOIN {node_field_data} fen on n.nid = fen.nid and fen.langcode = 'en'
        LEFT JOIN {node_field_data} ffr on n.nid = ffr.nid and ffr.langcode = 'fr'
      WHERE uuid IN (:ids[])", array(':ids[]' => $uuid));
    return $result->fetchAllAssoc('uuid');
  }
}


class SC_Common {
  const Created = '25BED78C-4957-4165-998A-CA1B52F67497';
  const CreatedBy = '5DD74568-4D4B-44C1-B513-0AF5F4CDA34F';
  const UpdatedBy = 'BADD9CF9-53E0-4D0C-BCC0-2D784C282F6A';
  const Revision = '8CDC337E-A112-42FB-BBB4-4143751E123F';
  const Updated = 'D9CF14B1-FA16-4BA6-9288-E8A174D4D522';
  const Owner = '52807595-0F8F-4B20-8D2A-CB71D28C6103';
  const Lock = '001DD393-96C5-490B-924A-B0F25CD9EFD8';
  const NeverPublish = '9135200A-5626-4DD8-AB9D-D665B8C11748';
  const Description = 'FF2DC376-BF87-49AA-96AA-07AAF8E06EEC';
  //used for page, bredcrumb, and menu title
  const Title = 'B009F46D-ADF9-44DB-9BEB-E012B37F047E';
  //only used to override the html title tag (ie.. if exists then title tag = MetaTitle + TitleSuffix
  const MetaTitle = '7E88E7FB-9C4E-4835-B6D8-66E600945BC0';
  const BreadcrumbTitle = 'EE1BF613-C6D4-4068-B47F-36198FBAEC3F';
  const TitleSuffix = 'F2C9FFAB-208D-4FBF-AC62-C0872D0CD119';
  const HeadScript = 'AE6E18B2-D633-4B39-84BD-D14C9B57D35D';
  const UACode = 'F9C76717-CFCE-49DF-9BF0-D32EC6F77593';
  const GoogleSitemap = 'E72B0A22-910F-44F8-9AB4-6734DFD600A0';
  const GoogleSitemapChange = 'B5564085-75A2-4F2E-BD59-0C8D6F2388E5';
  const Events = '113DD1A5-9DBD-4B0F-99BA-D5F9B0D0B5CA';
  const EventsTitle = '5AB66675-F841-4B49-949D-FD63AE3EA580';
  const EventsLink = 'F6A16025-34C5-482B-B4DE-380C47D86FD7';
  const BreakingNews = 'D4143EA5-E7E0-46DC-A296-68631FF596A6';
  const BreakingNewsTitle = '79BA3E0D-C5F9-4450-B37B-B0B3E88D6EDB';
  const BreakingNewsLink = '3E0A059F-B411-4F09-B95D-50F3C6AD8CAC';
  const Hotspots = '8550A49E-95DF-4D90-B1EF-042A62925E21';
  const Source = '1B86697D-60CA-4D80-83FB-7555A2E6CE1C';
  const DisplayName = 'B5E02AD9-D56F-4C41-A065-A133DB87BDEB';
  const EmptyHotspot = '{C4EFFD72-FB6D-4620-B47D-CA34A0A16B41}';
  const Publish = '86FE4F77-4D9A-4EC3-9ED9-263D03BD1965';
  const Unpublish = '7EAD6FD6-6CF1-4ACA-AC6B-B200E7BAFE88';
}

class SC_Hotspots extends SC_Common {
  const Html = '8B845CC4-3A79-45B6-9B51-41E43B098390';
}

class SC_Home_Common extends SC_Common {
  const Banner3 = '136B34FA-D7E9-4A0E-B88A-DDFC79E1A825';
  const Banner4 = '6F24738A-BD4D-4FAE-B424-B9452D2190E8';
  const ContactHtml = 'F51721B3-AF7D-4700-96D6-C6E8948D80BB';
  const ContactHeaderText = 'E284EE7B-1FA1-4099-8E61-408ECFFF7E22';
  const ContentModules = '89DFB5B7-3457-46B0-A7CA-4D0636A81AAF';
  const CurrentLocationHome = '80F62487-04D3-4092-A501-D58235287D6A';
  const CurrentLocation = 'BEC91FB8-B19D-459B-8E57-692460AB14E2';
  const DonateNow = '1A11501C-9356-4787-A7C6-A361DDA16DB2';
  const Follow = '8A49B8C0-0C19-4DDE-AD5B-4CB01FE50819';
  const FooterLogos = 'BBB1818C-B4A2-4383-92D7-A61EF6E530FE';
  const IntranetLink = 'ECB827A2-AE16-4ADB-A69C-0C7889F7CDEC';
  const QuickLinksHeader = '599A1DC0-145E-4575-A052-3839D3ECD646';
  const QuickLinks = '77A1525D-31B1-444C-A9E8-8AC6B2A3FE9F';
  const QuickLinks2 = 'BAA08038-E008-45A0-8CE8-ECF0CA4597C1';
  const QuickLinks3 = '5B20351D-BD8D-4D62-A430-9D0630651775';
  const Settings = '5E286539-893B-45B6-8D92-D3CF4A19C410';
  const Navigation = '4062054E-CE86-43DD-91CB-602FDEB08324';
}

class SC_Home extends SC_Home_Common {
  const Banner1 = '099F58D1-7572-43B1-9E93-DF18275B0763';
  const Banner2 = 'F267BBB9-09C3-4FCA-BA43-3C6A3A1E461D';
  const Bookmark = 'B4F46A62-8B76-40F4-81ED-237A3D46B493';
  const Copyright = '521568F0-928A-4D7E-A0A9-225BE92A1D1C';
}

class SC_Province extends SC_Home_Common {
  const Banner1 = 'BABC20F2-97D9-4D02-9C5E-3F961AD38143';
  const Banner2 = 'FB95A538-3518-425C-BF1F-77614188A4C4';
}

class SC_Chapter extends SC_Home_Common {
  const Banner1 = '8699421C-2B70-47DD-BD6C-F4A53E38B46D';
  const Banner2 = '74C2AD41-521B-477B-A816-7ED3F90A4529';
}

class SC_Homepage extends SC_Home_Common {
  const Banner1 = '099F58D1-7572-43B1-9E93-DF18275B0763';
  const Banner2 = 'F267BBB9-09C3-4FCA-BA43-3C6A3A1E461D';
}



class SC_File {
  const Description = 'BA8341A1-FF30-47B8-AE6A-F4947E4113F0';
  const Title = '3F4B20E9-36E6-4D45-A423-C86567373F82';
  const Keywords = '2FAFE7CB-2691-4800-8848-255EFA1D31AA';
  const Size = '6954B7C7-2487-423F-8600-436CB3B6DC0E';
  const Mime = '6F47A0A5-9C94-4B48-ABEB-42D38DEF6054';
  const Extension = 'C06867FE-9A43-4C7D-B739-48780492D06F';
}

class SC_Image extends SC_File {
  const Alt = '65885C44-8FCD-4A7F-94F1-EE63703FE193';
}

class SC_Zip extends SC_File {
  const FileCount = '2611ED8C-ECFF-4449-B25A-20D84B62363A';
}

class SC_ContentModules extends SC_Common {
  const Image = '2F1266AA-D96E-48F0-A6DD-296A7C404DF1';
  const Title = '498E970C-A8D9-4025-8324-D75B4E1A384A';
  const Html = '14647041-7FC1-4832-BCA9-34AF975427FF';
  const Link = '0F16E5C6-EC62-4492-9F65-610DCEB2B600';
  const IntroText = '9221BAE5-3DDF-4CE8-B61E-D99A05F3D8F6';
}


class SC_News extends SC_Common {
  const Link = '7489A3AA-0E4C-4F41-8627-D0B60D344237';
  const Text = '5EA25E24-B499-41BF-986F-D383293E572B';
}

class SC_Event extends SC_Common {
  const Link = '2BE940CF-0304-47BC-9EEF-5C62A0574270';
  const Text = '9C42E64C-2B7C-4CAB-A8F5-1A63081016ED';
}

class SC_Banner extends SC_Common {
  const Image = '786420AA-43C4-4FD7-94D8-A8DBC64447E6';
  const Link = '05B62145-6853-46D4-B3A0-BFA31F36695E';
  const OnClick = 'BB2DA47C-989D-4915-904A-80E811B9AF6C';
}

class SC_LandingPage extends SC_Common {
  const Content = '4573E3BF-86A7-4F5F-A930-A9FC85CBEC9F';
  const ContentModules = 'B36338D8-E259-4800-85F7-6700B7023E3C';
}

class SC_GenericPage extends SC_Common {
  const Content = 'B564E6C4-EECD-449B-B49B-D3F540948A77';
  const HideDonate = '75EC1F6A-7003-4B73-ADE4-A110726CE8D2';
}

class SC_Article extends SC_Common {
  const Content = '40F9E379-F9DB-452B-8DE1-AFA54539C2E9';
  const Image = 'C43D6365-775D-477E-9906-90E86975908A';
  const Video = 'DEB03288-1E6F-4B7B-B6AB-2839CEFA0B01';
  const Tags = '43729974-5D24-4D56-A82C-C3EBABED5D03';
}

class SC_ArticleNoRail extends SC_Common {
  const Content = '2323596C-1651-404E-8F40-B916B47913E7';
  const Image = '5AFA86C6-CD8E-4B15-B6D4-D65A6D789ED2';
  const Video = 'C29D13F8-7C00-49EA-A292-2C3C5A93D3A3';
  const Tags = '734D7EDE-0DBC-49C4-B45E-800F4E1F321B';
}

class SC_ArticleWide extends SC_Common {
  const Content = 'A2C9D074-8CAC-46D9-8FA2-99B9A9A2FD6F';
  const Image = '0908CAC6-A731-4752-8A86-FD3CA5DBA8AA';
  const Video = 'B5E89B48-020E-47CB-8987-4F4BE59D12E0';
  const Tags = 'E043C5A9-A9B8-473A-8BDF-A168A50FED2C';
  const IncludeDonate = '54827592-756F-4C9B-91DB-E9B32843D869';
}

class SC_BasicPage extends SC_Common {
  const Content = 'B564E6C4-EECD-449B-B49B-D3F540948A77';
  const Related = '62B442E0-EC2A-470A-86B7-0A908EDD859D';
  const HideDonate = '75EC1F6A-7003-4B73-ADE4-A110726CE8D2';
}