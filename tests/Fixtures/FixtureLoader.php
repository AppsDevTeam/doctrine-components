<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\Fixtures;

use ADT\DoctrineComponents\Tests\Fixtures\Entity\Author;
use ADT\DoctrineComponents\Tests\Fixtures\Entity\Book;
use ADT\DoctrineComponents\Tests\Fixtures\Entity\Publisher;
use ADT\DoctrineComponents\Tests\Fixtures\Entity\Tag;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

final class FixtureLoader
{
	public const PUBLISHER_ALFA = 1;
	public const PUBLISHER_BETA = 2;
	public const PUBLISHER_GAMA = 3;

	public const TAG_PHP = 1;
	public const TAG_SQL = 2;
	public const TAG_JS = 3;

	public const AUTHOR_ADAM = 1;
	public const AUTHOR_BEATA = 2;
	public const AUTHOR_CYRIL = 3;
	public const AUTHOR_DAVID = 4;
	public const AUTHOR_EVA = 5;

	public const BOOK_ALFA = 1;
	public const BOOK_BETA = 2;
	public const BOOK_GAMA = 3;
	public const BOOK_DELTA = 4;
	public const BOOK_ORPHAN = 5;

	public static function load(EntityManagerInterface $em): void
	{
		$publishers = [];
		foreach ([['Alfa', 'CZ'], ['Beta', 'SK'], ['Gama', null]] as $_data) {
			$publishers[] = $_publisher = new Publisher($_data[0], $_data[1]);
			$em->persist($_publisher);
		}

		$tags = [];
		foreach (['php', 'sql', 'js'] as $_name) {
			$tags[] = $_tag = new Tag($_name);
			$em->persist($_tag);
		}

		$adam = (new Author('Adam Novák'))
			->setEmail('adam@example.com')
			->setIsActive(true)
			->setRating(5)
			->setBirthDate(new DateTimeImmutable('1980-01-15'))
			->setPublisher($publishers[0])
			->addTag($tags[0])
			->addTag($tags[1]);

		$beata = (new Author('Beata Malá'))
			->setEmail('beata@example.com')
			->setIsActive(true)
			->setRating(3)
			->setBirthDate(new DateTimeImmutable('1990-06-30'))
			->setPublisher($publishers[0])
			->addTag($tags[1]);

		$cyril = (new Author('Cyril Velký'))
			->setEmail(null)
			->setIsActive(false)
			->setRating(null)
			->setBirthDate(null)
			->setPublisher($publishers[1]);

		$david = (new Author('David Adamec'))
			->setEmail('david@example.com')
			->setIsActive(true)
			->setRating(10)
			->setBirthDate(new DateTimeImmutable('2000-12-01'))
			->setPublisher(null)
			->addTag($tags[2]);

		$eva = (new Author('Eva Nová'))
			->setEmail('eva@example.com')
			->setIsActive(false)
			->setRating(7)
			->setBirthDate(new DateTimeImmutable('1975-03-20'))
			->setPublisher($publishers[2])
			->addTag($tags[0]);

		foreach ([$adam, $beata, $cyril, $david, $eva] as $_author) {
			$em->persist($_author);
		}

		$books = [
			[new Book('Alfa kniha'), 100, '2010-01-01', $adam],
			[new Book('Beta kniha'), 200, '2012-05-05', $adam],
			[new Book('Gama kniha'), null, null, $beata],
			[new Book('Delta kniha'), 300, '2020-10-10', $david],
			[new Book('Sirotek'), 50, null, null],
		];

		foreach ($books as [$_book, $_price, $_publishedAt, $_author]) {
			$_book->setPrice($_price);
			$_book->setPublishedAt($_publishedAt ? new DateTimeImmutable($_publishedAt) : null);
			if ($_author) {
				$_author->addBook($_book);
			}
			$em->persist($_book);
		}

		$em->flush();
		$em->clear();
	}
}
