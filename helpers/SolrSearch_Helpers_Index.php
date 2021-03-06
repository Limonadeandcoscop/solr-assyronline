<?php

/**
 * @package     omeka
 * @subpackage  solr-search
 * @copyright   2012 Rector and Board of Visitors, University of Virginia
 * @license     http://www.apache.org/licenses/LICENSE-2.0.html
 */


class SolrSearch_Helpers_Index
{


    /**
     * Connect to Solr.
     *
     * @param array $options An array of connection parameters.
     *
     * @return Apache_Solr_Service
     * @author David McClure <david.mcclure@virginia.edu>
     **/
    public static function connect($options=array())
    {

        $server = array_key_exists('solr_search_host', $options)
            ? $options['solr_search_host']
            : get_option('solr_search_host');

        $port = array_key_exists('solr_search_port', $options)
            ? $options['solr_search_port']
            : get_option('solr_search_port');

        $core = array_key_exists('solr_search_core', $options)
            ? $options['solr_search_core']
            : get_option('solr_search_core');

        return new Apache_Solr_Service($server, $port, $core);

    }

    /**
     * This indexes something that implements Mixin_ElementText into a Solr Document.
     *
     * @param array                $fields The fields to index.
     * @param Mixin_ElementText    $item   The item containing the element texts.
     * @param Apache_Solr_Document $doc    The document to index everything into.
     * @return void
     * @author Eric Rochester <erochest@virginia.edu>
     **/
    public static function indexItem($fields, $item, $doc)
    {
        foreach ($item->getAllElementTexts() as $text) {
            $field = $fields->findByText($text);

            // Set text field.
            if ($field->is_indexed) {
                $doc->setMultiValue($field->indexKey(), $text->text);
            }

            // Set string field.
            if ($field->is_facet) {
                $doc->setMultiValue($field->facetKey(), $text->text);
            }
        }
    }


    /**
     * This takes an Omeka_Record instance and returns a populated
     * Apache_Solr_Document.
     *
     * @param Omeka_Record $item The record to index.
     *
     * @return Apache_Solr_Document
     * @author Eric Rochester <erochest@virginia.edu>
     **/
    public static function itemToDocument($item)
    {
        $fields = get_db()->getTable('SolrSearchField');

        $doc = new Apache_Solr_Document();
        $doc->setField('id', "Item_{$item->id}");
        $doc->setField('resulttype', 'Item');
        $doc->setField('model', 'Item');
        $doc->setField('modelid', $item->id);

        /*** Facettes Assyr ***/

        // Collection : le champ DC:Publisher
        $publisher = metadata($item, array('Dublin Core', 'Publisher'));
        $doc->setField('assyr_collection', $publisher);  

        // Sous-collection : toutes les DC:Provenance ne commencant pas par "Acquisition history :"
        $values = metadata($item, array('Dublin Core', 'Provenance'), array('all' => true));
        $prefix = "Acquisition history :";
        foreach($values as $value) {
            if (substr($value, 0, strlen($prefix)) != $prefix) {
                $doc->setField('assyr_souscollection', trim(ucfirst($value)));  
            }
        }

        // Période historique : toutes les DC:Temporal Coverage qui commencent par "Period remarks :"
        $values = metadata($item, array('Dublin Core', 'Temporal Coverage'), array('all' => true));
        $prefix = "Period remarks :";
        foreach($values as $value) {
            if (substr($value, 0, strlen($prefix)) == $prefix) {
                $value = trim(ucfirst(str_replace($prefix, '', $value)));
                if (strpos($value, '(')) {
                    $value = trim(substr($value, 0, strpos($value, '(')));
                }
                $doc->setField('assyr_periode',  $value);
            }
        }

        // Aire géographique : toutes les DC:Spatial Coverage qui commencant par "Provenience remarks :"
        $values = metadata($item, array('Dublin Core', 'Spatial Coverage'), array('all' => true));
        $prefix = "Provenience remarks :";
        foreach($values as $value) {
            if (substr($value, 0, strlen($prefix)) == $prefix) {
                $doc->setField('assyr_aire',  trim(ucfirst(str_replace($prefix, '', $value))));  
            }
        }

        // Matériau : toutes les DC:Medium
        $value = metadata($item, array('Dublin Core', 'Medium'));
        if (strlen(trim($value))) {
            $doc->setField('assyr_materiau', trim(ucfirst($value)));  
        }

        // Hauteur, diamètre et poids : toutes les DC:Format qui commencent par "Height :", "Width :" et "Weight :"
        $values = metadata($item, array('Dublin Core', 'Format'), array('all' => true));
        foreach($values as $value) {

            $prefix = 'Height :';
            if (substr($value, 0, strlen($prefix)) == $prefix) {

                // Formatte la valeur
                $value = trim(ucfirst(str_replace($prefix, '', $value)));
                $value = trim(str_replace(' mm', '', $value));

                // Gère les intervals
                if (is_numeric($value)) {
                    $interval = null;
                    if ($value >= 0 && $value <= 9) {
                        $interval = "1-9 mm";
                    } elseif ($value >= 10 && $value <= 19) {
                        $interval = "10-19 mm";
                    } elseif ($value >= 20 && $value <= 29) {
                        $interval = "20-29 mm";
                    } elseif ($value >= 30 && $value <= 49) {
                        $interval = "30-49 mm";
                    } elseif ($value >= 50 && $value <= 55) {
                        $interval = "50-55 mm";
                    } elseif ($value >= 55 ) {
                        $interval = "> 55mm";
                    }
                    $doc->setField('assyr_hauteur', $interval);  
                } else {
                    echo "Erreur lors de l'indexation  : \"<b>".$value."</b>\" est une hauteur invalide (<a target='_blank' href='".admin_url('/items/show/'.$item->id)."'>sceau #$item->id</a>)<br />";
                }
            } 

            $prefix = 'Width :';
            if (substr($value, 0, strlen($prefix)) == $prefix) {

                $value = trim(ucfirst(str_replace($prefix, '', $value)));
                $value = trim(str_replace(' mm', '', $value));

                if (is_numeric($value)) {
                    $interval = null;
                    if ($value >= 4 && $value <= 9) {
                        $interval = "4-9 mm";
                    } elseif ($value >= 10 && $value <= 14) {
                        $interval = "10-14 mm";
                    } elseif ($value >= 15 && $value <= 19) {
                        $interval = "15-19 mm";
                    } elseif ($value >= 20 && $value <= 24) {
                        $interval = "20-24 mm";
                    } elseif ($value >= 25 && $value <= 29) {
                        $interval = "25-29 mm";                        
                    } elseif ($value >= 30 && $value <= 34) {
                        $interval = "30-34 mm";                                                
                    } elseif ($value >= 34 ) {
                        $interval = "> 34mm";
                    }
                    $doc->setField('assyr_diametre', $interval);
                } else {
                    echo "Erreur lors de l'indexation  : \"<b>".$value."</b>\" est une largeur invalide (<a target='_blank' href='".admin_url('/items/show/'.$item->id)."'>sceau #$item->id</a>)<br />";
                }
                
            } 

            $prefix = 'Weight :';
            if (substr($value, 0, strlen($prefix)) == $prefix) {

                $value = trim(ucfirst(str_replace($prefix, '', $value)));
                $value = trim(str_replace('g', '', $value));

                if (is_numeric($value)) {
                    $interval = null;
                    if ($value >= 1 && $value <= 9) {
                        $interval = "1-9 g";
                    } elseif ($value >= 10 && $value <= 19) {
                        $interval = "10-19 g";
                    } elseif ($value >= 20 && $value <= 29) {
                        $interval = "20-29 g";
                    } elseif ($value >= 30 && $value <= 39) {
                        $interval = "30-39 g";
                    } elseif ($value >= 40 && $value <= 49) {
                        $interval = "40-49 g";                        
                    } elseif ($value >= 50 && $value <= 59) {
                        $interval = "50-59 g";                                                
                    } elseif ($value >= 60 && $value <= 80) {
                        $interval = "60-80 g";                                                                        
                    } elseif ($value >= 81 ) {
                        $interval = "> 80 g";
                    }
                    $doc->setField('assyr_poids', $interval);
                } else {
                    echo "Erreur lors de l'indexation  : \"<b>".$value."</b>\" est un poids invalide (<a target='_blank' href='".admin_url('/items/show/'.$item->id)."'>sceau #$item->id</a>)<br />";
                }
                
            } 

            $prefix = 'Thickness :';
            if (substr($value, 0, strlen($prefix)) == $prefix) {

                $value = trim(ucfirst(str_replace($prefix, '', $value)));
                $value = trim(str_replace(' mm', '', $value));
                $value = trim(str_replace(' m', '', $value));

                if (is_numeric($value)) {
                    $interval = null;
                    if ($value >= 2 && $value <= 4) {
                        $interval = "2-4 mm";
                    } elseif ($value >= 5 && $value <= 6) {
                        $interval = "5-6 mm";
                    } elseif ($value >= 7 && $value <= 9) {
                        $interval = "7-9 mm";
                    } elseif ($value > 10 ) {
                        $interval = "> 9 mm";
                    }
                    $doc->setField('assyr_poids', $interval);
                    $doc->setField('assyr_diametre_perfore', $interval);
                } else {
                    echo "Erreur lors de l'indexation  : \"<b>".$value."</b>\" est un diamètre perforé invalide (<a target='_blank' href='".admin_url('/items/show/'.$item->id)."'>sceau #$item->id</a>)<br />";
                }

            }             
        }

        // Thème iconographique : tous les DC:Subject qui commencent par "Subgenre remarks :"
        // Mots clés : tous les DC:Subject qui ne commencent pas par "Subgenre remarks :"
        $values = metadata($item, array('Dublin Core', 'Subject'), array('all' => true));
        $prefix = "Subgenre remarks :";
        foreach($values as $value) {
            if (substr($value, 0, strlen($prefix)) == $prefix) {
                $doc->setField('assyr_icono', trim(ucfirst(str_replace($prefix, '', $value))));  
            } else {
                $doc->setMultiValue('assyr_motscles', trim(ucfirst($value)));  
            }
        }
        


        // extend $doc to to include and items public / private status
        $doc->setField('public', $item->public);

        // Title:
        $title = metadata($item, array('Dublin Core', 'Title'));
        $doc->setField('title', $title);

        // Elements:
        self::indexItem($fields, $item, $doc);

        // Tags:
        foreach ($item->getTags() as $tag) {
            $doc->setMultiValue('tag', $tag->name);
        }

        // Collection:
        if ($collection = $item->getCollection()) {
            $doc->collection = metadata(
                $collection, array('Dublin Core', 'Title')
            );
        }

        // Item type:
        if ($itemType = $item->getItemType()) {
            $doc->itemtype = $itemType->name;
        }

        $doc->featured = (bool) $item->featured;

        // File metadata
        foreach ($item->getFiles() as $file) {
            self::indexItem($fields, $file, $doc);
        }

        return $doc;

    }


    /**
     * This returns the URI for an Omeka_Record.
     *
     * @param Omeka_Record $record The record to return the URI for.
     *
     * @return string $uri The URI to access the record with.
     * @author Eric Rochester <erochest@virginia.edu>
     **/
    public static function getUri($record)
    {
        $uri    = '';
        $action = 'show';
        $rc     = get_class($record);

        if ($rc === 'SimplePagesPage') {
            $uri = url($record->slug);
        }

        else if ($rc === 'ExhibitPage') {

            $exhibit = $record->getExhibit();
            $exUri   = self::getSlugUri($exhibit, $action);
            $uri     = "$exUri/$record->slug";

        } else if (property_exists($record, 'slug')) {
            $uri = self::getSlugUri($record, $action);
        } else {
            $uri = record_url($record, $action);
        }

        // Always index public URLs.
        $uri = preg_replace('|/admin/|', '/', $uri, 1);

        return $uri;
    }


    /**
     * This returns the URL for an Omeka_Record with a 'slug' property.
     *
     * @param Omeka_Record $record The sluggable record to create the URL for.
     * @param string       $action The action to access the record with.
     *
     * @return string $uri The URI for the record.
     * @author Eric Rochester <erochest@virginia.edu>
     **/
    public static function getSlugURI($record, $action)
    {
        // Copied from omeka/applications/helpers/UrlFunctions.php, record_uri.
        // Yuck.
        $recordClass = get_class($record);
        $inflector   = new Zend_Filter_Word_CamelCaseToDash();
        $controller  = strtolower($inflector->filter($recordClass));
        $controller  = Inflector::pluralize($controller);
        $options     = array(
            'controller' => $controller,
            'action'     => $action,
            'id'         => $record->slug
        );
        $uri = url($options, 'id');

        return $uri;
    }


    /**
     * This pings the Solr server with the given options and returns true if
     * it's currently up.
     *
     * @param array $options The configuration to test. Missing values will be
     * pulled from the current set of options.
     *
     * @return bool
     * @author Eric Rochester <erochest@virginia.edu>
     */
    public static function pingSolrServer($options=array())
    {
        try {
            return self::connect($options)->ping();
        } catch (Exception $e) {
            return false;
        }
    }


    /**
     * This re-indexes everything in the Omeka DB.
     *
     * @return void
     * @author Eric Rochester
     **/
    public static function indexAll($options=array())
    {

        $solr = self::connect($options);

        $db     = get_db();
        $table  = $db->getTable('Item');
        $select = $table->getSelect();

        // Removed in order to index both public and private items
        // $table->filterByPublic($select, true);
        $table->applySorting($select, 'id', 'ASC');

        $excTable = $db->getTable('SolrSearchExclude');
        $excludes = array();
        foreach ($excTable->findAll() as $e) {
            $excludes[] = $e->collection_id;
        }
        if (!empty($excludes)) {
            $select->where(
                'collection_id IS NULL OR collection_id NOT IN (?)',
                $excludes);
        }

        // First get the items.
        $pager = new SolrSearch_DbPager($db, $table, $select);
        while ($items = $pager->next()) {
            foreach ($items as $item) {
                $docs = array();
                $doc = self::itemToDocument($item);
                $docs[] = $doc;
                $solr->addDocuments($docs);
            }
            $solr->commit();
        }

        // Now the other addon stuff.
        $mgr  = new SolrSearch_Addon_Manager($db);
        $docs = $mgr->reindexAddons();
        $solr->addDocuments($docs);
        $solr->commit();

        $solr->optimize();

    }


    /**
     * This deletes everything in the Solr index.
     *
     * @param array $options The configuration to test. Missing values will be
     * pulled from the current set of options.
     *
     * @return void
     * @author Eric Rochester
     **/
    public static function deleteAll($options=array())
    {

        $solr = self::connect($options);

        $solr->deleteByQuery('*:*');
        $solr->commit();
        $solr->optimize();

    }


}
