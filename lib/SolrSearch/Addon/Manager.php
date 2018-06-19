<?php

/**
 * @package     omeka
 * @subpackage  solr-search
 * @copyright   2012 Rector and Board of Visitors, University of Virginia
 * @license     http://www.apache.org/licenses/LICENSE-2.0.html
 */


/**
 * This manages the process of getting the addon information from the config
 * files and using them to index a document.
 **/
class SolrSearch_Addon_Manager
{


    /**
     * The database this will interface with.
     *
     * @var Omeka_Db
     **/
    var $db;

    /**
     * The addon directory.
     *
     * @var string
     **/
    var $addonDir;

    /**
     * The parsed addons
     *
     * @var array of SolrSearch_Addon_Addon
     **/
    var $addons;


    /**
     * This instantiates a SolrSearch_Addon_Manager
     *
     * @param Omeka_Db $db       The database to initialize everything with.
     * @param string   $addonDir The directory for the addon config files.
     **/
    function __construct($db, $addonDir=null)
    {
        $this->db       = $db;
        $this->addonDir = $addonDir;
        $this->addons   = null;

        if ($this->addonDir === null) {
            $this->addonDir = SOLR_DIR . '/addons';
        }
    }


    /**
     * This parses all the JSON configuration files in the addon directory and
     * returns the addons.
     *
     * @param SolrSearch_Addon_Config $config The configuration parser. If
     * null, this is created.
     *
     * @return array of SolrSearch_Addon_Addon
     * @author Eric Rochester <erochest@virginia.edu>
     **/
    public function parseAll($config=null)
    {
        if (is_null($config)) {
            $config = new SolrSearch_Addon_Config($this->db);
        }
        if (is_null($this->addons)) {
            $this->addons = array();
        }

        $this->addons = array_merge(
            $this->addons, $config->parseDir($this->addonDir)
        );

        return $this->addons;
    }


    /**
     * A helper method to the return the addon for the record.
     *
     * @param Omeka_Record $record The record to find an addon for.
     *
     * @return SolrSearch_Addon_Addon|null $addon The corresponding addon.
     * @author Eric Rochester <erochest@virginia.edu>
     **/
    public function findAddonForRecord($record)
    {
        $hit = null;

        $recordTable = get_class($record);
        foreach ($this->addons as $key => $addon) {
            if ($recordTable == $addon->table) {
                $hit = $addon;
                break;
            }
        }

        return $hit;
    }


    /**
     * For a given record, re-save all child addon records, if any exist.
     *
     * @param Omeka_Record $record The record.
     *
     * @author David McClure <david.mcclure@virginia.edu>
     **/
    public function resaveChildren($record)
    {

        // Get the record's addon.
        $addon = $this->findAddonForRecord($record);
        if (is_null($addon)) return;

        foreach ($addon->children as $childAddon) {

            // Load each of the child records.
            $children = $this->db->getTable($childAddon->table)->findBySql(
                "{$childAddon->parentKey}=?", array($record->id)
            );

            // Resave each of the children.
            foreach ($children as $child) $child->save();

        }

    }


    /**
     * For a given record, re-save the remote parent record, if one exists.
     *
     * @param Omeka_Record $record The record.
     *
     * @author David McClure <david.mcclure@virginia.edu>
     **/
    public function resaveRemoteParent($record)
    {

        // Get the record type.
        $table = get_class($record);

        // Iterate over all addon fields.
        foreach ($this->addons as $addon) {
            foreach ($addon->fields as $field) {

                // Match remote fields that point to the record's type.
                if ($field->remote && $field->remote->table == $table) {

                    $parentTable = $this->db->getTable($addon->table);
                    $parentIdKey = $field->remote->key;

                    // Load the parent and re-save.
                    $parent = $parentTable->find($record->$parentIdKey);
                    $parent->save();

                }

            }
        }

    }


    /**
     * This reindexes all the addons and returns the Solr documents created.
     *
     * @param SolrSearch_Addon_Config $config The configuration parser. If
     * null, this is created. If given, this forces the Addons to be re-parsed;
     * otherwise, they're only re-parsed if they haven't been yet.
     *
     * @return array of Apache_Solr_Document $docs The documents generated by
     * indexing the Addon records.
     * @author Eric Rochester <erochest@virginia.edu>
     **/
    public function reindexAddons($config=null)
    {
        $docs = array();
        $idxr = new SolrSearch_Addon_Indexer($this->db);

        if (is_null($this->addons) || !is_null($config)) {
            $this->parseAll($config);
        }

        $docs = $idxr->indexAll($this->addons);

        return $docs;
    }


    /**
     * This indexes a single record.
     *
     * @param Omeka_Record $record The record to index.
     * @param SolrSearch_Addon_Config $config The configuration parser. If
     * null, this is created. If given, this forces the Addons to be re-parsed;
     * otherwise, they're only re-parsed if they haven't been yet.
     *
     * @return Apache_Solr_Document|null $doc The indexed document or null, if
     * the record's not to be indexed.
     * @author Eric Rochester <erochest@virginia.edu>
     **/
    public function indexRecord($record, $config=null)
    {
        $doc  = null;
        $idxr = new SolrSearch_Addon_Indexer($this->db);

        if (is_null($this->addons) || !is_null($config)) {
            $this->parseAll($config);
        }

        $addon = $this->findAddonForRecord($record);
        if (!is_null($addon) && $idxr->isRecordIndexed($record, $addon)) {
            $doc = $idxr->indexRecord($record, $addon);
        }

        return $doc;
    }


    /**
     * This returns the Solr ID for the record.
     *
     * @param Omeka_Record $record The record to index.
     * @param SolrSearch_Addon_Config $config The configuration parser. If
     * null, this is created. If given, this forces the Addons to be re-parsed;
     * otherwise, they're only re-parsed if they haven't been yet.
     *
     * @return string|null
     * @author Eric Rochester <erochest@virginia.edu>
     **/
    public function getId($record, $config=null)
    {
        $id   = null;
        $idxr = new SolrSearch_Addon_Indexer($this->db);

        if (is_null($this->addons) || !is_null($config)) {
            $this->parseAll($config);
        }

        $addon = $this->findAddonForRecord($record);
        if (!is_null($addon)) {
            $id = "{$addon->table}_{$record->id}";
        }

        return $id;
    }


}
