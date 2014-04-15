SimpleCache
===========

A custom wrapper around Memcached providing support for "tags" as well as a few other enhancements. Adheres as closely as possible to core [Memcached](http://www.php.net/manual/en/book.memcached.php) API but ads the notion of a tag array as an added input.


## Tags

Tags allow you to associate an arbitrary string to a set of Memcached values. You can then use these tags to clear a set of values without having to keep track of those values Ids.

```php
// Assign a memcached object
SimpleCache::getInstance()->set_mc($configured_memcached_obj);

// Set key/values along with an optional tag array
$cache = SimpleCache::getInstance();
$cache->set('key 1','value 1',array('red','square'));
$cache->set('key 2','value 2',array('triangle','blue'));
$cache->set('key 3','value 3',array('square','orange','green'));

// Clear tags
$cache->clearTags(array('square'));

// Get cache values. All values associated with 'square' have been cleared
$cache->get('key 1',array('red','square'))            === FALSE;
$cache->get('key 2',array('blue','triangle'))         === 'value 2';
$cache->get('key 3',array('Orange','Square','Green')) === FALSE;
```

## Methods

### set(), get() and delete()
The key fact to understand when working with tags is that any tags used when setting a value must also be used when getting or deleting the value. Tag case and order does not matter.

 ```php
 $cache = SimpleCache::getInstance();

 // Set a key/value to expire after 24 hours and associate with tags 'bar' & 'baz'
 $cache->set('foo','qux',array('bar','baz'),86400);

// Consider the following gets...
$cache->get('foo-foo', array('bar','baz')) === FALSE; // id mismatch
$cache->get('foo', array('bar','xyzzy'))   === FALSE; // tags mismatch
$cache->get('foo')                         === FALSE; // tags mismatch
$cache->get('foo', array('bar','baz'))     === 'qux'; // correct value returned
ache->get('foo', array('BAZ','bar'))       === 'qux'; // order & case do not matter

// Delete follows the same rules
$cache->get('foo', array('bar','baz')) === TRUE; // id & tags are correct
```

### increment() and decrement()
Both methods use the underlying [Memcached::increment](http://www.php.net/manual/en/memcached.increment.php) and [Memcached::decrement](http://www.php.net/manual/en/memcached.decrement.php).

```php
$cache = SimpleCache::getInstance();

// Initial value
$cache->set('foo',3,array('bar','baz'));

// Increment by 1
$cache->increment('foo',array('bar','baz'),1);

// New value is 4
$cache->get('foo',array('bar','baz')) === 4;

// Decrement by 3
$cache->decrement('foo',array('bar','baz'),3);

// New value is 1
$cache->get('foo',array('bar','baz')) === 1;
```


### setMulti() and getMulti()
The Multi methods allow you to do get and set in batches which can be more efficient. The underlying [Memcached::setMulti](http://www.php.net/manual/en/memcached.setmulti.php) and [Memcached::getMulti](http://www.php.net/manual/en/memcached.getmulti.php) are used.

```php
$cache = SimpleCache::getInstance();

// Setting multiple values
$cache->setMulti(array(
    array('id'=>'foo', 'value'=>'qux', 'tags'=>array('bar','baz')),
    array('id'=>'foo2', 'value'=>'qux2', 'tags'=>array('baz')),
    array('id'=>'foo3', 'value'=>'qux3', 'tags'=>array('purple','circle'))
));

// Getting multiple values
$values = $cache->getMulti(array(
    array('id'=>'foo', 'tags'=>array('bar','baz')),
    array('id'=>'foo2', 'tags'=>array('baz')),
    array('id'=>'foo3', 'tags'=>array('purple','circle'))
));

// Accessing items from the getMulti call
$values['foo'] === 'qux';
$values['foo2'] === 'qux2';
```
