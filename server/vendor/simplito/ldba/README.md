LDBA
====

//fault tolerance / automatic crash recovery thanks transactional's style write

LDBA is a high-performance,  low-memory-footprint, single-file embedded database for key/value storage and retrieval written in pure PHP. 

It is inspired by Erlang's Dets and Berkeley DB sofware and includes implementation of [extended linear hashing](https://en.wikipedia.org/wiki/Linear_hashing)
 for fast key/value access and implementation of a fast [buddy storage allocator](https://en.wikipedia.org/wiki/Buddy_memory_allocation) for file space management.

LDBA supports insertion and deletion of records and lookup by exact key match only. 
Applications may iterate over all records stored in a database, but
the order in which they are returned is undefined.

The size of LDBA files cannot exceed 2GB.

LDBA provides functions compatible with PHP [DBA](http://php.net/manual/en/book.dba.php) (Database Abstraction Layer) for easy adoption in existing software.

### Instalation

Via [composer](http://getcomposer.org).
Run the following command to install it:
```
composer require simplito/ldba
```

```php
<?php
require 'vendor/autoload.php'

$dbh = ldba_open("test.ldb", "c");
if (ldba_exists("counter", $dbh)) {
    $counter = intval(ldba_fetch("counter", $dbh));
} else {
    $counter = 0;
}
ldba_replace("counter", $counter + 1);
ldba_close($dbh);
```

### Requirements

* PHP 5.4+