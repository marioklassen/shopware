<?php declare(strict_types=1);

namespace Shopware\Core\System\Test\CustomEntity;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\FloatType;
use Doctrine\DBAL\Types\StringType;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Test\Product\ProductBuilder;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Feature;
use Shopware\Core\Framework\Struct\ArrayEntity;
use Shopware\Core\Framework\Test\App\AppSystemTestBehaviour;
use Shopware\Core\Framework\Test\IdsCollection;
use Shopware\Core\Framework\Test\TestCaseBase\AdminApiTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\SalesChannelApiTestBehaviour;
use Shopware\Core\System\CustomEntity\Schema\CustomEntityPersister;
use Shopware\Core\System\CustomEntity\Schema\CustomEntitySchemaUpdater;
use Shopware\Core\System\CustomEntity\Xml\CustomEntitySchema;
use Shopware\Core\System\CustomEntity\Xml\Entities;
use Shopware\Core\System\CustomEntity\Xml\Entity;
use Shopware\Core\System\CustomEntity\Xml\Field\BoolField;
use Shopware\Core\System\CustomEntity\Xml\Field\EmailField;
use Shopware\Core\System\CustomEntity\Xml\Field\FloatField;
use Shopware\Core\System\CustomEntity\Xml\Field\IntField;
use Shopware\Core\System\CustomEntity\Xml\Field\JsonField;
use Shopware\Core\System\CustomEntity\Xml\Field\ManyToManyField;
use Shopware\Core\System\CustomEntity\Xml\Field\ManyToOneField;
use Shopware\Core\System\CustomEntity\Xml\Field\OneToManyField;
use Shopware\Core\System\CustomEntity\Xml\Field\OneToOneField;
use Shopware\Core\System\CustomEntity\Xml\Field\StringField;
use Shopware\Core\System\CustomEntity\Xml\Field\TextField;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

class CustomEntityTest extends TestCase
{
    use KernelTestBehaviour;
    use AdminApiTestBehaviour;
    use SalesChannelApiTestBehaviour;
    use AppSystemTestBehaviour;

    public function setUp(): void
    {
        Feature::skipTestIfInActive('FEATURE_NEXT_17441', $this);
    }

    /**
     * Cleanup, schema update and container initialisation costs much time, so we
     * only call this functions once and then execute all none schema updating tests
     * directly
     */
    public function testNoneSchemaChanges(): void
    {
        $this->cleanUp();

        $container = $this->initBlogEntity();

        $this->testPersist();

        $this->testCreateFromXml();

        $this->testEntityApi();

        $this->testRepository($container);

        $this->cleanUp();
    }

    public function testSchemaCreate(): void
    {
        $this->cleanUp();

        $this->initBlogEntity();

        $schema = $this->getContainer()
            ->get(Connection::class)
            ->getSchemaManager()
            ->createSchema();

        static::assertTrue($schema->hasTable('custom_entity_blog'));
        static::assertTrue($schema->hasTable('custom_entity_blog_translation'));
        static::assertTrue($schema->hasTable('custom_entity_blog_comment'));
        static::assertTrue($schema->hasTable('custom_entity_blog_comment_translation'));
        static::assertTrue($schema->hasTable('custom_entity_blog_product'));

        $table = $schema->getTable('custom_entity_blog');
        static::assertTrue($table->hasColumn('id'));
        static::assertTrue($table->hasColumn('position'));
        static::assertTrue($table->hasColumn('rating'));
        static::assertTrue($table->hasColumn('payload'));
        static::assertTrue($table->hasColumn('email'));
        static::assertTrue($table->hasColumn('top_seller_id'));

        $table = $schema->getTable('custom_entity_blog_translation');
        static::assertTrue($table->hasColumn('title'));
        static::assertTrue($table->hasColumn('content'));
        static::assertTrue($table->hasColumn('display'));

        $table = $schema->getTable('custom_entity_blog_comment');
        static::assertTrue($table->hasColumn('id'));
        static::assertTrue($table->hasColumn('email'));
        static::assertTrue($table->hasColumn('custom_entity_blog_id'));

        $table = $schema->getTable('custom_entity_blog_comment_translation');
        static::assertTrue($table->hasColumn('title'));
        static::assertTrue($table->hasColumn('content'));

        $table = $schema->getTable('custom_entity_blog_product');
        static::assertTrue($table->hasColumn('custom_entity_blog_id'));
        static::assertTrue($table->hasColumn('product_id'));

        $table = $schema->getTable('category');
        static::assertTrue($table->hasColumn('custom_entity_blog_id'));

        $this->cleanUp();
    }

    public function testSchemaUpdate(): void
    {
        $this->cleanUp();

        $entities = CustomEntitySchema::createFromXmlFile(__DIR__ . '/_fixtures/custom-entity-test/Resources/install.xml');

        $this->cleanUp();

        $this->getContainer()
            ->get(CustomEntityPersister::class)
            ->update($entities->toStorage(), null);

        $this->getContainer()
            ->get(CustomEntitySchemaUpdater::class)
            ->update();

        $schema = $this->getSchema();
        static::assertTrue($schema->hasTable('custom_entity_blog'));
        static::assertTrue($schema->getTable('custom_entity_blog')->hasColumn('position'));
        static::assertTrue($schema->getTable('custom_entity_blog')->hasColumn('top_seller_id'));
        static::assertTrue($schema->getTable('custom_entity_blog')->hasColumn('author_id'));

        static::assertTrue($schema->hasTable('custom_entity_blog_comment'));
        static::assertTrue($schema->getTable('custom_entity_blog_comment')->hasColumn('custom_entity_blog_id'));
        static::assertTrue($schema->getTable('product')->hasColumn('custom_entity_blog_comment_id'));

        $entities = CustomEntitySchema::createFromXmlFile(__DIR__ . '/_fixtures/custom-entity-test/Resources/update.xml');
        $this->getContainer()
            ->get(CustomEntityPersister::class)
            ->update($entities->toStorage(), null);

        $this->getContainer()
            ->get(CustomEntitySchemaUpdater::class)
            ->update();

        $schema = $this->getSchema();

        static::assertTrue($schema->hasTable('custom_entity_blog'));
        static::assertFalse($schema->getTable('custom_entity_blog')->hasColumn('position'));
        static::assertFalse($schema->getTable('custom_entity_blog')->hasColumn('top_seller_id'));
        static::assertFalse($schema->getTable('custom_entity_blog')->hasColumn('author_id'));
        static::assertFalse($schema->getTable('product')->hasColumn('custom_entity_blog_comment_id'));

        static::assertInstanceOf(StringType::class, $schema->getTable('custom_entity_blog')->getColumn('rating')->getType());
        static::assertInstanceOf(FloatType::class, $schema->getTable('custom_entity_blog_translation')->getColumn('title')->getType());

        //many-to-many association removed
        static::assertTrue($schema->hasTable('custom_entity_blog_comment'));
        static::assertFalse($schema->hasTable('custom_entity_blog_product'));
        static::assertFalse($schema->hasTable('custom_to_remove'));

        $this->cleanUp();
    }

    private function testCreateFromXml(): void
    {
        $entities = CustomEntitySchema::createFromXmlFile(__DIR__ . '/_fixtures/custom-entity-test/Resources/entities.xml');

        $expected = new CustomEntitySchema(
            __DIR__ . '/_fixtures/custom-entity-test/Resources',
            new Entities([
                new Entity([
                    'name' => 'custom_entity_blog',
                    'storeApiAware' => true,
                    'fields' => [
                        new IntField(['name' => 'position', 'storeApiAware' => true]),
                        new FloatField(['name' => 'rating', 'storeApiAware' => true]),
                        new StringField(['name' => 'title', 'storeApiAware' => true, 'required' => true, 'translatable' => true]),
                        new TextField(['name' => 'content', 'storeApiAware' => true, 'allowHtml' => true, 'translatable' => true]),
                        new BoolField(['name' => 'display', 'storeApiAware' => true, 'translatable' => true]),
                        new JsonField(['name' => 'payload', 'storeApiAware' => false]),
                        new EmailField(['name' => 'email', 'storeApiAware' => false]),
                        new ManyToManyField(['name' => 'products', 'storeApiAware' => true, 'reference' => 'product']),
                        new OneToManyField(['name' => 'links', 'storeApiAware' => true, 'reference' => 'category']),
                        new OneToManyField(['name' => 'comments', 'storeApiAware' => true, 'reference' => 'custom_entity_blog_comment']),
                        new ManyToOneField(['name' => 'top_seller', 'storeApiAware' => true, 'reference' => 'product', 'required' => true]),
                        new OneToOneField(['name' => 'link_product', 'storeApiAware' => false, 'reference' => 'product']),
                    ],
                ]),
                new Entity([
                    'name' => 'custom_entity_blog_comment',
                    'storeApiAware' => true,
                    'fields' => [
                        new StringField(['name' => 'title', 'storeApiAware' => true, 'required' => true, 'translatable' => true]),
                        new TextField(['name' => 'content', 'storeApiAware' => true, 'allowHtml' => true, 'translatable' => true]),
                        new EmailField(['name' => 'email', 'storeApiAware' => false]),
                        new ManyToOneField(['name' => 'recommendation', 'reference' => 'product', 'storeApiAware' => true, 'required' => false]),
                    ],
                ]),
            ])
        );

        static::assertEquals($expected, $entities);
    }

    private function testPersist(): void
    {
        $storage = $this->getContainer()->get(Connection::class)
            ->fetchAllAssociative('SELECT * FROM custom_entity ORDER BY name');

        static::assertCount(2, $storage);

        $fields = [
            ['name' => 'position', 'type' => 'int', 'storeApiAware' => true],
            ['name' => 'rating', 'type' => 'float', 'storeApiAware' => true],
            ['name' => 'title', 'type' => 'string', 'required' => true, 'translatable' => true, 'storeApiAware' => true],
            ['name' => 'content', 'type' => 'text', 'allowHtml' => true, 'translatable' => true, 'storeApiAware' => true],
            ['name' => 'display', 'type' => 'bool', 'translatable' => true, 'storeApiAware' => true],
            ['name' => 'payload', 'type' => 'json', 'storeApiAware' => false],
            ['name' => 'email', 'type' => 'email', 'storeApiAware' => false],
            ['name' => 'products', 'type' => 'many-to-many', 'reference' => 'product', 'storeApiAware' => true],
            ['name' => 'links', 'type' => 'one-to-many', 'reference' => 'category', 'storeApiAware' => true],
            ['name' => 'comments', 'type' => 'one-to-many', 'reference' => 'custom_entity_blog_comment', 'storeApiAware' => true],
            ['name' => 'top_seller', 'type' => 'many-to-one', 'required' => true, 'reference' => 'product', 'storeApiAware' => true],
            ['name' => 'link_product', 'type' => 'one-to-one', 'reference' => 'product', 'storeApiAware' => false],
        ];

        static::assertEquals('custom_entity_blog', $storage[0]['name']);
        static::assertEquals($fields, json_decode($storage[0]['fields'], true));

        $fields = [
            ['name' => 'title', 'type' => 'string', 'required' => true, 'translatable' => true, 'storeApiAware' => true],
            ['name' => 'content', 'type' => 'text', 'allowHtml' => true, 'translatable' => true, 'storeApiAware' => true],
            ['name' => 'email', 'type' => 'email', 'storeApiAware' => false],
            ['name' => 'recommendation', 'type' => 'many-to-one', 'reference' => 'product', 'storeApiAware' => true, 'required' => false],
        ];
        static::assertEquals('custom_entity_blog_comment', $storage[1]['name']);
        static::assertEquals($fields, json_decode($storage[1]['fields'], true));

        static::assertNotNull($storage[0]['created_at']);
        static::assertNotNull($storage[1]['created_at']);

        $entities = CustomEntitySchema::createFromXmlFile(__DIR__ . '/_fixtures/custom-entity-test/Resources/entities.xml');

        $this->getContainer()
            ->get(CustomEntityPersister::class)
            ->update($entities->toStorage(), null);

        $storage = $this->getContainer()->get(Connection::class)
            ->fetchAllAssociative('SELECT * FROM custom_entity ORDER BY name');

        static::assertCount(2, $storage);
        static::assertNotNull($storage[0]['updated_at']);
        static::assertNotNull($storage[1]['updated_at']);
    }

    private function testRepository(ContainerInterface $container): void
    {
        $ids = new IdsCollection();

        $blogs = [
            'id' => $ids->get('blog'),
            'position' => 1,
            'rating' => 2.2,
            'title' => 'Test',
            'content' => 'Test <123>',
            'display' => true,
            'payload' => ['foo' => 'Bar'],
            'email' => 'test@test.com',
            'links' => [
                ['id' => $ids->get('category-1'), 'name' => 'test'],
                ['id' => $ids->get('category-2'), 'name' => 'test'],
            ],
            'topSeller' => (new ProductBuilder($ids, 'p2'))->price(100)->build(),
            'comments' => [
                ['title' => 'test', 'content' => 'test', 'email' => 'test@test.com'],
                ['title' => 'test', 'content' => 'test', 'email' => 'test@test.com'],
            ],
        ];

        /** @var EntityRepository|null $repository */
        $repository = $container->get('custom_entity_blog.repository');
        static::assertInstanceOf(EntityRepository::class, $repository);

        $repository->create([$blogs], Context::createDefaultContext());

        $criteria = new Criteria($ids->getList(['blog']));
        $criteria->addAssociation('comments');
        $criteria->addAssociation('topSeller');
        $criteria->addAssociation('links');

        $blogs = $repository->search($criteria, Context::createDefaultContext());

        static::assertCount(1, $blogs);
        $blog = $blogs->first();

        static::assertInstanceOf(ArrayEntity::class, $blog);
        static::assertEquals($ids->get('blog'), $blog->getId());
        static::assertInstanceOf(ProductEntity::class, $blog->get('topSeller'));
        static::assertCount(2, $blog->get('comments'));
        static::assertCount(2, $blog->get('links'));
    }

    private function testEntityApi(): void
    {
        $ids = new IdsCollection();

        $blog = [
            'id' => $ids->get('blog'),
            'position' => 1,
            'rating' => 2.2,
            'title' => 'Test',
            'content' => 'Test <123>',
            'display' => true,
            'payload' => ['foo' => 'Bar'],
            'email' => 'test@test.com',
            'links' => [
                ['id' => $ids->get('category-1'), 'name' => 'test'],
                ['id' => $ids->get('category-2'), 'name' => 'test'],
            ],
            'topSeller' => (new ProductBuilder($ids, 'p3'))
                ->price(100)
                ->build(),
            'comments' => [
                ['title' => 'test', 'content' => 'test', 'email' => 'test@test.com'],
                ['title' => 'test', 'content' => 'test', 'email' => 'test@test.com'],
            ],
        ];

        $client = $this->getBrowser();

        $client->request('POST', '/api/custom-entity-blog', [], [], [], json_encode($blog));
        static::assertSame(Response::HTTP_NO_CONTENT, $client->getResponse()->getStatusCode());

        $client->request('POST', '/api/search/custom-entity-blog', [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $response = json_decode($client->getResponse()->getContent(), true);

        static::assertArrayHasKey('data', $response);
        static::assertCount(1, $response['data']);
        static::assertArrayHasKey('id', $response['data'][0]);
        static::assertArrayHasKey('rating', $response['data'][0]);
    }

    private function transactional(\Closure $closure): void
    {
        $this->getContainer()->get(Connection::class)->transactional($closure);
    }

    private function initBlogEntity(): ContainerInterface
    {
        $this->loadAppsFromDir(__DIR__ . '/_fixtures');

        $container = KernelLifecycleManager::bootKernel()->getContainer();

        static::assertTrue($container->has('custom_entity_blog.repository'));

        return $container;
    }

    private function getSchema(): Schema
    {
        return $this->getContainer()
            ->get(Connection::class)
            ->getSchemaManager()
            ->createSchema();
    }

    private function cleanUp(): void
    {
        try {
            $this->getContainer()->get(Connection::class)->executeStatement('DELETE FROM custom_entity_blog');
        } catch (Exception $e) {
        }

        $this->getContainer()->get(Connection::class)->executeStatement('DELETE FROM product WHERE product_number IN (:number)', ['number' => ['p1', 'p2', 'p3']], ['number' => Connection::PARAM_STR_ARRAY]);

        $this->getContainer()->get(Connection::class)->executeStatement('DELETE FROM tax WHERE name = :name', ['name' => 't1']);

        $this->getContainer()->get(Connection::class)->executeStatement('DELETE FROM custom_entity');

        $this->getContainer()->get(Connection::class)->executeStatement('DELETE FROM app WHERE name = :name', ['name' => 'custom-entity-test']);

        $this->getContainer()->get(CustomEntitySchemaUpdater::class)->update();
    }
}
