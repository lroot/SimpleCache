<?php

/**
 * A custom wrapper around Memcached providing support for "tags" as well as
 * a few other enhancements. Tags are implemented using the
 * {@link http://code.google.com/p/memcached/wiki/FAQ#Namespaces namespace concept}.
 *
 * @link http://us3.php.net/manual/en/book.memcached.php Memcached info
 */
class SimpleCache
{

    /**
     * The prefix for generated id+tags keys stored in memcached
     */
    const KEY_PREFIX = "KEY_";

    /**
     * The prefix for tags stored in memcached
     */
    const TAG_PREFIX = "TAG_";

    /**
     * Singleton object instance
     * @type SimpleCache
     */
    private static $instance;

    /**
     * Memcached instance
     * @type Memcached
     */
    private $mc;

    /**
     * An array of tag key values that have already been fetched
     * @type array
     */
    static private $tagCache = array();

    /**
     * An array of values that have already been fetched from memcached
     * @type array
     */
    static private $valueCache = array();

    /**
     * Stores a persistent reference to memcached
     */
    public  function set_mc(Memcached $memcached) {
        $this->mc = $memcached;
    }

    /**
     * @return Memcached
     */
    public function get_mc() {
        return self::$mc;
    }

    /**
     * Returns the single instance of the cache class
     * @return SimpleCache
     */
    public static function getInstance() {
        if (!isset(self::$instance)) {
            self::$instance = new SimpleCache();
        }
        return self::$instance;
    }

    /**
     * Sets a value in cache based on the provided ID and tags. Tags are
     * factored into the final storage key. That means when fetching data the
     * same ID and tags must be used (tag order & case does not matter). In
     * other words, setting the value:
     *
     *  $id='foo', $tags=array('bar','baz')
     *
     * The following get operations will result in:
     *
     *  $id='foo2',$tags=array('bar','baz') => NOT FOUND, id mismatch
     *  $id='foo', $tags=array('bar')       => NOT FOUND, tags mismatch
     *  $id='foo', $tags=array()            => NOT FOUND, tags mismatch
     *  $id='foo', $tags=array('bar','baz') => FOUND
     *  $id='foo', $tags=array('baz','BAR') => FOUND, order & case do not matter
     *
     * @return boolean           True if cache was set successfully, otherwise
     *                           false
     * @param string $id         A unique value that identifies a given item
     * @param mixed  $value      The value of an item. Complex values will be
     *                           serialized & deserialized automatically
     * @param array  $tags       Optional set of tags as strings
     * @param int    $expiration Optional expiration time. Default is infinite.
     *                           For info on values see {@link http://us3.php.net/manual/en/memcached.expiration.php}
     */
    public function set($id, $value, $tags = array(), $expiration = 0) {
        $key = $this->getKey($id,$tags);
        self::$valueCache[$key] = $value;
        return $this->mc->set($key, $value, $expiration);
    }

    /**
     * Sets multiple values in cache based on a set of provided ID and Tags.
     * This function is a counterpart to {@link AG_Cache::set} which provides
     * details on setting values in cache. Note that the expiration time is
     * applied to all items.
     *
     * @return boolean          True if cache was set successfully, otherwise
     *                          false
     * @param array $items      An array of items or each item is:
     *                          array( 'id'=>$id,
     *                                 'value'=>$value [, 'tags'=>$tags])
     * @param int   $expiration Optional expiration time. Default is infinite.
     *                          For info on values see {@link http://us3.php.net/manual/en/memcached.expiration.php}
     */
    public function setMulti($items, $expiration = 0) {
        return $this->mc->setMulti($this->formatItemsForMulti($items), $expiration);
    }

    /**
     *  Increments a numeric item's value by the specified offset and returns
     *  the result. If the item's value is not numeric, it is treated as if the
     *  value were 0. If the item/key does not exist it will return false.
     *
     * @return int | boolean
     * @param string $id     A unique value that identifies a given item
     * @param array  $tags   Optional set of tags as strings
     * @param int    $offset The amount by which to increment the item's value;
     *                       defaults to 1.
     */
    public function increment($id, $tags = array(), $offset = 1) {
        $key = $this->getKey($id,$tags);
        unset (self::$valueCache[$key]); // ensure we clear local cache
        return $this->mc->increment($key, $offset);
    }

    /**
     *  Decrement a numeric item's value by the specified offset and returns
     *  the result. If the item's value is not numeric, it is treated as if the
     *  value were 0. If the operation would decrease the value below 0, the new
     *  value will be 0. If the item/key does not exist it return false.
     *
     * @return int | boolean
     * @param string $id     A unique value that identifies a given item
     * @param array  $tags   Optional set of tags as strings
     * @param int    $offset The amount by which to increment the item's value;
     *                       defaults to 1.
     */
    public function decrement($id, $tags = array(), $offset = 1) {
        $key = $this->getKey($id,$tags);
        unset (self::$valueCache[$key]); // ensure we clear local cache
        return $this->mc->decrement($key, $offset);
    }

    /**
     * Returns a given item from cache based on the given ID and Tags. An
     * optional Read-through callback can be provided. In the case of a cache
     * miss, the callback will be fired so the value can be determined, placed
     * into cache and returned to the orginal calling function.
     *
     * @link http://us3.php.net/manual/en/language.pseudo-types.php#language.types.callback Info on how to create callback values
     * @link http://us3.php.net/manual/en/memcached.callbacks.read-through.php Info on the structure of Memcached Read-through callbacks
     *
     * @return mixed The value stored in cache or FALSE otherwise
     * @param string   $id       A unique value that identifies a given item
     * @param array    $tags     Optional set of tags as strings
     * @param callback $callback Optional read-through caching callback or null
     *
     */
    public function get($id, $tags=array(), $callback=null) {
        $key = $this->getKey($id,$tags);

        if (isset(self::$valueCache[$key]))
        {
            $result = self::$valueCache[$key];
        }
        else
        {
            $result = $this->mc->get( $key, $callback);
            if ($result)
            {
                self::$valueCache[$key] = $result;
            }
        }

        return $result;
    }

    /**
     * Fetches multiple id + tag items from cache and returns an array of
     * results. The result is is an hash of values in which you can access the
     * results by id which was originally provided. An example result could be:
     *
     * <code>
     * 'MyId' =>
     *   array
     *     'id' => string 'MyId' (length=4)
     *     'tags' =>
     *       array
     *         0 => string 'baz' (length=3)
     *     'key' => string 'KEY_549a4b8522bce039481750c6920d53a5' (length=36)
     * </code>
     *
     * Everything that was put into the getMulti call is echoed back in the
     * result. Also included is the key value used for storage in memcached as
     * well as the value returned from memcached or null if not found. This
     * means the value for a given ID can bee accessed using:
     *
     * <code>
     * $result = AG_Cache::getInstance()->getMulti(array('id'=>'MyId'));
     * echo $result['MyId']['value'];
     * </code>
     *
     * This function will check that each id+tag that is passed in is uniaue, so
     * the number of items sent may not be equal to the number of items
     * returned.
     *
     * Also since Ids do not have to be unique (its the id+tag values that
     * create a unique key) the results for items with duplicate Ids will be
     * nested as an indexed array. Example:
     *
     * <code>
     * $result = AG_Cache::getInstance()->getMulti(array('id'=>'DupeId'));
     * echo $result['DupeId'][0]['value'];
     * echo $result['DupeId'][1]['value'];
     *
     * // Would return total duplicate items
     * echo count($result['DupeId']);
     *
     * // A way to check for multiple values
     * echo isset($result['DupeId']['__overloaded']);
     * </code>
     *
     * @return array
     * @param array $items An array of items where each item is:
     *                     array( 'id'=>$id [, 'tags'=>array($tag1,$tag2...)])
     */
    public function getMulti($items) {

        // Derive key values from provided ID and tag items
        $keys =   array_keys($this->formatItemsForMulti($items));

        // Fetch values from cache
        $values = $this->mc->getMulti( array_keys($this->formatItemsForMulti($items) ));

        // Splice items with keys and setup our results array
        $results = array_map(create_function('$item, $key', '$item["key"]=$key;$item["value"]=null;return $item;'), $items, $keys);

        // Create a result array that provides direct access by id
        foreach ($items as $index => $value) {
            $result = &$results[$index];
            $result['value'] = $values[$result['key']];

            /* If the same ID is provided multiple times (IDs do not 
             * need to be unique, since its the id+tag values that create a 
             * unique key) we need to return the results for th given ID as an 
             * array of values.
             */
            if (isset($results[$result['id']])) {
                $resultById = &$results[$result['id']];
                if (!isset($resultById['__overloaded'])) {
                    $resultById = array($resultById);
                    $resultById['__overloaded'] = 1;
                }
                $resultById[] = $result;
            } else {
                $results[$result['id']] = $result;
            }
        }

        return $results;
    }

    /**
     * Deletes a given item from cache based on the given ID and Tags.
     * @param string $id
     * @param array  $tags
     * @return bool
     */
    public function delete($id, $tags=array()) {
        $key = $this->getKey($id,$tags);
        unset (self::$valueCache[$key]);
        return $this->mc->delete( $key );
    }

    /**
     * Takes a hash of cache items (ids, values, tags) and formats them for
     * use in memcache 'multi' function calls.
     * @return array
     * @param array $items an array of cache items (ids, values, tags)
     */
    protected function formatItemsForMulti ($items) {
        $result= array();
        foreach ($items as $item) {
            $id =    $item['id'];
            $val =   $item['value'];
            $tags =  $item['tags'];
            $result[$this->getKey($id,$tags)] = $val;
        }
        return $result;
    }

    /**
     * Returns the key string for the given id and tags (optional). Value is
     * suitable for use in key value storage such as memcached.
     * @return string
     * @param  string $id   A unique value that identifies a given item
     * @param  array  $tags Optional set of tags as strings
     */
    public function getKey ($id, $tags=array()) {

        // Don't worry about tag stuff if we dont have tags
        if (is_array($tags) && count($tags)>0) {
            $tags = array_map("AG_Cache::_sanatizeTag", $tags);
            $tags = array_filter($tags,"AG_Cache::_filterTag");
            $tags = array_unique( $tags );
            $tags = self::getInstance()->getVersionedTagNamesSorted($tags);
            return APPLICATION_ENV.self::KEY_PREFIX.md5( $id.implode($tags) );
        }
        return  self::KEY_PREFIX.md5( $id );
    }

    /**
     * Filter tag names based on arbitrary criteria.
     * This method should not be called directly.
     * @return Boolean
     * @param  string $tag The tag name to filter
     */
    static public function _filterTag ($tag) {
        if ($tag == '') {
            return false;
        }
        return true;
    }

    /**
     * Makes tag name lower case and removes all non alpha numeric characters.
     * @param $tag
     * @return mixed|string
     */
    static public function _sanatizeTag ($tag) {
        $tag = strtolower($tag);
        $tag = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $tag);
        return $tag;
    }

    /**
     * Returns an array of versioned tag names (ie 'tagName0' or 'tagName22')
     * suitable for use in key generation. It will fetch and set tag version
     * values in memcached as well as store returned values locally.
     * @param array $tags
     * @return array|mixed A set of tags as strings
     * @throws AG_Exception
     */
    protected function getVersionedTagNamesSorted ($tags=array()) {
        // Dont bother if we dont have any tags
        if (!is_array($tags) || count($tags)==0) return array();

        // Prefix all tag tag names
        $tagsPrefixed = array_map(create_function('$tag','return AG_Cache::TAG_PREFIX.$tag;'), $tags);

        // Store resulting tag names    
        $versionedTagNames = array();

        // Tags that are not in our local class cache & need to be fetched from memcached
        $tagsToFetch = array_filter($tagsPrefixed,"AG_Cache::_tagNotCached");

        // create tag names for local cached tags
        foreach (array_diff($tagsPrefixed, $tagsToFetch) as $cachedTag) {
            $versionedTagNames[] = $cachedTag . self::$tagCache[$cachedTag];
        }

        // Grab values for non cached tags; fectch from memcached in one query
        $tagResultItems = $this->mc->getMulti($tagsToFetch);
        if ($tagResultItems) {

            // Add to local cache and update our results array
            foreach ($tagResultItems as $tag => $value) {
                self::$tagCache[$tag] = $value;
                $versionedTagNames[] = $tag.$value;
            }
        }

        // These tags were not found in Memcached, set default values store them
        $tagResultItems = is_array($tagResultItems) ? $tagResultItems : array();
        $tagsMissingFromRemoteCache = array_diff($tagsToFetch, array_keys($tagResultItems) );
        $newTagItems = array();
        foreach ($tagsMissingFromRemoteCache as $tag) {
            $newTagItems[$tag] =    0;
            self::$tagCache[$tag] = $newTagItems[$tag];
            $versionedTagNames[] =  $tag.$newTagItems[$tag];
        }
        if ( !$this->mc->setMulti($newTagItems) ) throw new AG_Exception(AG_Exception::GENERAL, 'Failed to set data in memcached. Result Code '.$this->mc->getResultCode());

        // Strip prefix and resort to ensure proper order
        $result = str_replace(self::TAG_PREFIX, '', $versionedTagNames);
        sort($result);
        return $result;
    }

    /**
     * Returns true if the tag is not cached locally, otherwise false
     * @param $tag
     * @return bool
     */
    static public function _tagNotCached($tag) {
        if ( isset(self::$tagCache[$tag]) ) {
            return false;
        }
        return true;
    }

    /**
     * Clears a set of tags by incrementing its version value in memcached. This
     * will result in a new version of the tag name. That means that a request
     * for any items that used these tags will result in a cache miss.
     * @return null
     * @param array   $tags    An array of tag name strings
     * @param boolean $setFlag Optional value that will initialize a tag in
     *                         memcached if its not found. Default is false
     *                         which means nothing happens if a tag is not
     *                         found.
     */
    static public function clearTags(array $tags, $setFlag=false) {
        $mc = self::getInstance()->mc;

        // Cleanup tags - this process is done for get and sets. If we dont
        // do it here as well we will get cache misses (ex this lowercases stuff)
        $tags = array_map("AG_Cache::_sanatizeTag",$tags);
        $tags = array_filter($tags,"AG_Cache::_filterTag");
        $tags = array_unique($tags);

        // Prefix all tag tag names
        $tagsPrefixed = array_map(create_function('$tag','return AG_Cache::TAG_PREFIX.$tag;'), $tags);

        foreach ($tagsPrefixed as $tag) {
            $result = $mc->increment($tag);
            if (!$result && $setFlag) {
                $mc->set($tag,0);
                self::$tagCache[$tag] = 0;
            } else {
                self::$tagCache[$tag]++;
            }
        }
    }

    /**
     * Empties the local value cache array. handy for long running processes
     * or when real time value is required.
     */
    static public function resetValueCache() {
        self::$valueCache = array();
    }
}
