<?php
declare(strict_types=1);

namespace TheCodingMachine\TDBM;

use Doctrine\Common\Cache\Cache;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\DateType;
use Doctrine\DBAL\Types\Type;
use Mouf\Database\SchemaAnalyzer\SchemaAnalyzer;

/**
 * This class is used to analyze the schema and return valuable information / hints.
 */
class TDBMSchemaAnalyzer
{
    private $connection;

    /**
     * @var Schema
     */
    private $schema;

    /**
     * @var string
     */
    private $cachePrefix;

    /**
     * @var Cache
     */
    private $cache;

    /**
     * @var SchemaAnalyzer
     */
    private $schemaAnalyzer;

    /**
     * @param Connection     $connection     The DBAL DB connection to use
     * @param Cache          $cache          A cache service to be used
     * @param SchemaAnalyzer $schemaAnalyzer The schema analyzer that will be used to find shortest paths...
     *                                       Will be automatically created if not passed
     */
    public function __construct(Connection $connection, Cache $cache, SchemaAnalyzer $schemaAnalyzer)
    {
        $this->connection = $connection;
        $this->cache = $cache;
        $this->schemaAnalyzer = $schemaAnalyzer;
    }

    /**
     * Returns a unique ID for the current connection. Useful for namespacing cache entries in the current connection.
     *
     * @return string
     */
    public function getCachePrefix(): string
    {
        if ($this->cachePrefix === null) {
            $this->cachePrefix = hash('md4', $this->connection->getHost().'-'.$this->connection->getPort().'-'.$this->connection->getDatabase().'-'.$this->connection->getDriver()->getName());
        }

        return $this->cachePrefix;
    }

    /**
     * Returns the (cached) schema.
     *
     * @return Schema
     */
    public function getSchema(): Schema
    {
        if ($this->schema === null) {
            $cacheKey = $this->getCachePrefix().'_immutable_schema';
            if ($this->cache->contains($cacheKey)) {
                $this->schema = $this->cache->fetch($cacheKey);
            } else {
                $this->schema = $this->connection->getSchemaManager()->createSchema();
                $this->castSchemaToImmutable($this->schema);
                $this->cache->save($cacheKey, $this->schema);
            }
        }

        return $this->schema;
    }

    private function castSchemaToImmutable(Schema $schema): void
    {
        foreach ($schema->getTables() as $table) {
            foreach ($table->getColumns() as $column) {
                $this->toImmutableType($column);
            }
        }
    }

    /**
     * Changes the type of a column to an immutable date type if the type is a date.
     * This is needed because by default, when reading a Schema, Doctrine assumes a mutable datetime.
     */
    private function toImmutableType(Column $column): void
    {
        $mapping = [
            Type::DATE => Type::DATE_IMMUTABLE,
            Type::DATETIME => Type::DATETIME_IMMUTABLE,
            Type::DATETIMETZ => Type::DATETIMETZ_IMMUTABLE,
            Type::TIME => Type::TIME_IMMUTABLE
        ];

        $typeName = $column->getType()->getName();
        if (isset($mapping[$typeName])) {
            $column->setType(Type::getType($mapping[$typeName]));
        }
    }

    /**
     * Returns the list of pivot tables linked to table $tableName.
     *
     * @param string $tableName
     *
     * @return string[]
     */
    public function getPivotTableLinkedToTable(string $tableName): array
    {
        $cacheKey = $this->getCachePrefix().'_pivottables_link_'.$tableName;
        if ($this->cache->contains($cacheKey) && is_array($this->cache->fetch($cacheKey))) {
            return $this->cache->fetch($cacheKey);
        }

        $pivotTables = [];

        $junctionTables = $this->schemaAnalyzer->detectJunctionTables(true);
        foreach ($junctionTables as $table) {
            $fks = $table->getForeignKeys();
            foreach ($fks as $fk) {
                if ($fk->getForeignTableName() == $tableName) {
                    $pivotTables[] = $table->getName();
                    break;
                }
            }
        }

        $this->cache->save($cacheKey, $pivotTables);

        return $pivotTables;
    }

    /**
     * Returns the list of foreign keys pointing to the table represented by this bean, excluding foreign keys
     * from junction tables and from inheritance.
     * It will also suppress doubles if 2 foreign keys are using the same columns.
     *
     * @return ForeignKeyConstraint[]
     */
    public function getIncomingForeignKeys(string $tableName): array
    {
        $junctionTables = $this->schemaAnalyzer->detectJunctionTables(true);
        $junctionTableNames = array_map(function (Table $table) {
            return $table->getName();
        }, $junctionTables);
        $childrenRelationships = $this->schemaAnalyzer->getChildrenRelationships($tableName);

        $fks = [];
        foreach ($this->getSchema()->getTables() as $table) {
            $uniqueForeignKeys = $this->removeDuplicates($table->getForeignKeys());
            foreach ($uniqueForeignKeys as $fk) {
                if ($fk->getForeignTableName() === $tableName) {
                    if (in_array($fk->getLocalTableName(), $junctionTableNames)) {
                        continue;
                    }
                    foreach ($childrenRelationships as $childFk) {
                        if ($fk->getLocalTableName() === $childFk->getLocalTableName() && $fk->getUnquotedLocalColumns() === $childFk->getUnquotedLocalColumns()) {
                            continue 2;
                        }
                    }
                    $fks[] = $fk;
                }
            }
        }

        return $fks;
    }

    /**
     * Remove duplicate foreign keys (assumes that all foreign yes are from the same local table)
     *
     * @param ForeignKeyConstraint[] $foreignKeys
     * @return ForeignKeyConstraint[]
     */
    private function removeDuplicates(array $foreignKeys): array
    {
        $fks = [];
        foreach ($foreignKeys as $foreignKey) {
            $key = implode('__`__', $foreignKey->getUnquotedLocalColumns());
            if (!isset($fks[$key])) {
                $fks[$key] = $foreignKey;
            }
        }

        return array_values($fks);
    }
}
