# Doctrine Components

## Install

```
composer require adt/doctrine-components
```

## Using `searchIn` and `*Join`

Entities used in examples.

* Library
  * Book
    * Author

Examples are in `LibraryQuery`


### Simple

Joins are done automatically when using `searchIn`

    public function byAuthorName(string $name) {
        $this->searchIn('books.author.name', $name);
    }


### Own joins

Use `BaseQuery::leftJoin` and `BaseQuery::innerJoin` to join with extended conditions and with simple filtering.

    public function byAuthorName(string $name) {
        $this->leftJoin('books', 'book', '...', '...');
        $this->innerJoin('book.author', author, '...', '...');
        $this->searchIn('books.author.name', $name);
    }


### Own filter

Use automatic joins and a custom extended filter

    public function byAuthorIsAdult(int $age = 18) {
        $this->addJoins('books.author');
        $this->filter[] = function (QueryBuilder $qb) use ($age) {
            $qb->andWhere('author >= :age', $age)
                ->setParameter('age', $age);
            };
        };
        return $this;
    }


### Own join and filter

Use `BaseQuery::leftJoin` and `BaseQuery::leftJoin` and a custom filter.

    public function byAuthorIsAdult(int $age = 18) {
        $this->leftJoin('....');
        $this->innerJoinJoin('....');
        $this->filter[] = function (QueryBuilder $qb) use ($age) {
            ....
        };
        return $this;
    }
