<?php

namespace NexOtaku\ModelMaker;

use Illuminate\Console\Command;
use Illuminate\Console\Concerns\InteractsWithIO;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use NexOtaku\MinimalFilesystem\Filesystem;
use NunoMaduro\LaravelConsoleMenu\Menu;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ModelMaker
{
    private bool    $isSelectedModel     = false;

    private string  $modelNameCamelCased = '';

    private string  $modelNameSnakeCased = '';

    private string  $tableName           = '';

    private Command $command;

    use InteractsWithIO;

    private function __construct(
        InputInterface  $input,
        OutputInterface $output,
        Command         $command
    ) {
        $this->input = $input;
        $this->output = $output;
        $this->command = $command;
    }

    public static function instance(
        InputInterface  $input,
        OutputInterface $output,
        Command         $command
    ): self {
        return new self(
            $input,
            $output,
            $command
        );
    }

    public function getCommands(): array
    {
        $migrationName = $this->getMigrationName();

        return [
            "php artisan make:migration {$migrationName} --create={$this->tableName}",
            "php artisan migrate",
            "php artisan make:model {$this->modelNameCamelCased}",
        ];
    }

    private function getMigrationName(): string
    {
        if (!$this->isSelectedModel()) {
            throw new \LogicException('Нельзя работать с миграцией не выбрав модель');
        }

        return "create_{$this->tableName}_table";
    }

    private function parseSpacedName(string $modelNameWithSpaces): void
    {
        $this->modelNameCamelCased = $this->getCamelCasedFromDelimitedBySpace($modelNameWithSpaces);
        $this->modelNameSnakeCased = $this->getSnakeCased($modelNameWithSpaces);
        $this->tableName = $this->pluralize($this->modelNameSnakeCased);
        $this->isSelectedModel = true;
    }

    private function selectModelCamelized(string $modelNameCamelized): void
    {
        $this->modelNameCamelCased = $modelNameCamelized;
        $this->modelNameSnakeCased = $this->getSnakeCasedFromCamelized($modelNameCamelized);
        $this->tableName = $this->pluralize($this->modelNameSnakeCased);
        $this->isSelectedModel = true;
    }

    /**
     * @param string $phrase
     * @param string $delimiter
     * @return string[]
     */
    private function getWords(string $phrase, string $delimiter): array
    {
        $words = explode($delimiter, trim($phrase));
        $filtered = [];

        foreach ($words as $word) {
            if (trim($word) === '') {
                continue;
            }

            $filtered [] = $word;
        }

        return $filtered;
    }

    /**
     * @param string $name
     * @return string[]
     */
    private function getWordsDelimitedBySpaces(string $name): array
    {
        return $this->getWords($name, ' ');
    }

    /**
     * @param string $name
     * @return string[]
     */
    private function getWordsDelimitedByUnderscore(string $name): array
    {
        return $this->getWords($name, '_');
    }

    /**
     * @param string $name
     * @return string[]
     */
    private function getWordsDelimitedByCapitalLetters(string $name): array
    {
        $parts = [];
        $part = '';
        $letters = str_split($name);

        foreach ($letters as $letter) {
            if ($letter !== strtolower($letter)) {
                if ($part !== '') {
                    $parts []= $part;
                }

                $part = $letter;

                continue;
            }

            $part .= $letter;
        }

        if ($part !== '') {
            $parts []= $part;
        }

        return $parts;
    }

    private function getCamelCasedFromDelimitedBySpace(string $name): string
    {
        return implode(
            '',
            array_map(function (string $value) {
                return $this->camelize($value);
            }, $this->getWordsDelimitedBySpaces($name))
        );
    }

    private function getSnakeCased(string $name): string
    {
        return implode('_', array_map('strtolower', $this->getWordsDelimitedBySpaces($name)));
    }

    private function isSelectedModel(): bool
    {
        return $this->isSelectedModel;
    }

    public function make(): void
    {
        $repeat = true;

        while ($repeat) {
            /** @var Menu $menu */
            $menu = $this->command->menu();
            $this->printMainMenuHeader($menu);

            $menu->setTitle('Выберите действие');

            $this->addSelectModelOptions($menu);

            $menu->addOptions(
                [
                    'addModel' => 'Добавить модель',
                ]
            );

            if ($this->isSelectedModel()) {
                $menu->addOptions(
                    [
                        'addFields' => 'Добавить поля',
                        'runMigrations' => 'Выполнить миграции',
                        'buildModel' => 'Создать модель',
                    ]
                );
            }

            $option = $menu->open();

            if (in_array($option, $this->getModels())) {
                $this->selectModelCamelized($option);

                continue;
            }

            switch ($option) {
                case 'addModel':
                    $this->addModel();
                    break;
                case 'addFields':
                    $this->loopAddFields();
                    break;
                case 'runMigrations':
                    $this->runMigrations();
                    break;
                case 'buildModel':
                    $this->buildModel();
                    break;

                case null:
                    $repeat = false;
                    break;
                default:
                    echo "Неизвестная опция - {$option}\n";
                    die();
            }
        }
    }

    private function getTables(): array
    {
        $databaseName = env('DB_DATABASE');
        $tables = DB::select("SELECT table_name FROM information_schema.tables WHERE table_schema = '{$databaseName}'");
        $table_names = [];

        foreach ($tables as $table) {
            $tableName = $table->table_name;

            if ($tableName === 'migrations') {
                continue;
            }

            $table_names[] = $tableName;
        }

        return $table_names;
    }

    private function getModels(): array
    {
        $tables = $this->getTables();
        $models = [];

        foreach ($tables as $table) {
            $camelizedPlural = $this->getCamelizedFromSnakeCased($table);
            $models []= $this->depluralize($camelizedPlural);
        }

        return $models;
    }

    private function getCamelizedFromSnakeCased(string $name): string
    {
        return implode(
            '',
            array_map(function (string $value) {
                return $this->camelize($value);
            }, $this->getWordsDelimitedByUnderscore($name))
        );
    }

    private function getSnakeCasedFromCamelized(string $camelizedName): string
    {
        return implode(
            '_',
            array_map(function (string $value) {
                return strtolower($value);
            }, $this->getWordsDelimitedByCapitalLetters($camelizedName))
        );
    }

    private function printMainMenuHeader(Menu $menu): void
    {
        $menu->addLineBreak();
        $menu->addStaticItem("Модель: " . ($this->isSelectedModel() ? $this->modelNameCamelCased : '(не выбрано)'));
        $menu->addStaticItem("Таблица: " . ($this->isSelectedModel() ? $this->tableName : '(не выбрано)'));

        $menu->addLineBreak();
    }

    private function addSelectModelOptions(Menu $menu): void
    {
        $models = $this->getModels();
        $modelsJoinedWithKeys = array_combine($models, $models);
        $menu->addOptions($modelsJoinedWithKeys);
    }

    private function addModel(): void
    {
        /** @var Menu $menu */
        $menu = $this->command->menu();
        $this->printMainMenuHeader($menu);

        $modelNameWithSpaces = $menu->addQuestion('Добавить модель', 'Название модели')
                                    ->open();

        if ($modelNameWithSpaces === null) {
            return;
        }

        $this->parseSpacedName($modelNameWithSpaces);
    }

    private function loopAddFields(): void
    {
        $repeat = true;

        while ($repeat) {
            /** @var Menu $menu */
            $menu = $this->command->menu();
            $this->printFields($menu);

            $fieldName = $menu->addQuestion('Добавить поле', 'Введите название поля')
                              ->open();

            if ($fieldName === null) {
                break;
            }

            $tableFieldName = $this->getSnakeCased($fieldName);

            /** @var Menu $menu */
            $menu = $this->command->menu();
            $this->printFields($menu);

            $option = $menu->setTitle("Выберите тип для поля \"{$tableFieldName}\"")
                           ->addOptions(
                               [
                                   'id' => 'ID',
                                   'idNull' => 'ID NULL',
                                   'string' => 'string',
                                   'stringNull' => 'string NULL',
                                   'text' => 'text',
                                   'textNull' => 'text NULL',
                                   'int' => 'INT',
                                   'intNull' => 'INT NULL',
                                   'bigInt' => 'BIGINT',
                                   'decimal' => 'DECIMAL (30,10)',
                                   'decimalNull' => 'DECIMAL (30,10) NULL',
                                   'boolean' => 'boolean',
                                   'timestampNull' => 'TIMESTAMP NULL',
                                   'jsonNull' => 'json NULL',
                               ]
                           )->open();

            switch ($option) {
                case 'id':
                    $this->addFieldToMigration("\$table->unsignedBigInteger('{$tableFieldName}');");
                    break;
                case 'idNull':
                    $this->addFieldToMigration("\$table->unsignedBigInteger('{$tableFieldName}')->nullable();");
                    break;
                case 'string':
                    $this->addFieldToMigration("\$table->string('{$tableFieldName}');");
                    break;
                case 'stringNull':
                    $this->addFieldToMigration("\$table->string('{$tableFieldName}')->nullable();");
                    break;
                case 'text':
                    $this->addFieldToMigration("\$table->text('{$tableFieldName}');");
                    break;
                case 'textNull':
                    $this->addFieldToMigration("\$table->text('{$tableFieldName}')->nullable();");
                    break;
                case 'int':
                    $this->addFieldToMigration("\$table->integer('{$tableFieldName}');");
                    break;
                case 'intNull':
                    $this->addFieldToMigration("\$table->integer('{$tableFieldName}')->nullable();");
                    break;
                case 'bigInt':
                    $this->addFieldToMigration("\$table->bigInteger('{$tableFieldName}');");
                    break;
                case 'decimal':
                    $this->addFieldToMigration("\$table->decimal('{$tableFieldName}');");
                    break;
                case 'decimalNull':
                    $this->addFieldToMigration("\$table->decimal('{$tableFieldName}')->nullable();");
                    break;
                case 'boolean':
                    $this->addFieldToMigration("\$table->boolean('{$tableFieldName}');");
                    break;
                case 'timestampNull':
                    $this->addFieldToMigration("\$table->timestamp('{$tableFieldName}')->nullable();");
                    break;
                case 'jsonNull':
                    $this->addFieldToMigration("\$table->json('{$tableFieldName}')->nullable();");
                    break;

                case null:
                    $repeat = false;
                    break;
                default:
                    echo "Неизвестная опция - {$option}\n";
                    die();
            }
        }
    }

    private function runMigrations(): void
    {
        $this->artisanRun('migrate:fresh');
    }

    private function buildModel(): void
    {
        $this->deleteModel($this->modelNameCamelCased);
        $this->artisanRun("make:model {$this->modelNameCamelCased}");
        $this->updateModelFields($this->modelNameCamelCased);
    }

    private function artisanRun(string $command): void
    {
        $artisanCommand = "php artisan {$command}";
        $output = shell_exec($artisanCommand);

        echo $output . "\n";
    }

    private function findMigrationFile(): ?string
    {
        $fs = new FileSystem();
        $migrations = $fs->searchFiles($this->getMigrationsPath(), '*' . $this->getMigrationName() . '.php');

        if (count($migrations) < 1) {
            return null;
        }

        sort($migrations);

        return $migrations[0];
    }

    private function createMigration(): void
    {
        if (!$this->isSelectedModel()) {
            throw new \LogicException('Нельзя работать с миграцией не выбрав модель');
        }

        $migrationName = $this->getMigrationName();

        $this->artisanRun("make:migration {$migrationName} --create={$this->tableName}");
    }

    private function getMigrationRows(): array
    {
        $migrationPath = $this->findMigrationFile();

        if ($migrationPath === null) {
            $this->createMigration();
            $migrationPath = $this->findMigrationFile();
        }

        if ($migrationPath === null) {
            throw new \LogicException('Не удалось найти миграцию');
        }

        $fs = new FileSystem();
        $content = $fs->readFile($migrationPath);

        return explode("\n", $content);
    }

    private function findRowMatch(array $rows, string $match, int $offset): int
    {
        $index = -1;

        for ($i = $offset; $i < count($rows); $i++) {
            $row = trim($rows[$i]);

            if ($row === $match) {
                $index = $i;

                break;
            }
        }

        return $index;
    }

    private function findRowStartsWith(array $rows, string $startsWith, int $offset): int
    {
        $index = -1;

        for ($i = $offset; $i < count($rows); $i++) {
            $row = trim($rows[$i]);

            if (str_starts_with($row, $startsWith)) {
                $index = $i;

                break;
            }
        }

        return $index;
    }

    private function printFields(Menu $menu): void
    {
        $rows = $this->getMigrationRows();
        $start = $this->findRowStartsWith($rows, 'Schema::create(', 0);

        if ($start === -1) {
            return;
        }

        $end = $this->findRowMatch($rows, '});', $start);

        if ($end === -1) {
            return;
        }

        $definitions = array_slice($rows, $start + 1, $end - $start - 1);

        foreach ($definitions as $definition) {
            $cleaned = trim($definition);
            $menu->addStaticItem($cleaned);
        }

        $menu->addLineBreak();
    }

    private function addFieldToMigration(string $fieldDefinition): void
    {
        $rows = $this->getMigrationRows();

        // Определяем номер строки завершающей "Schema::create(", изначально 19
        $index = -1;

        for ($i = 0; $i < count($rows); $i++) {
            $row = trim($rows[$i]);

            if ($row === '});') {
                // Если перед этой строкой идёт "$table->timestamps();" то идём на строку выше
                if (($i > 0) && (trim($rows[$i - 1]) === '$table->timestamps();')) {
                    $index = $i - 1;
                } else {
                    $index = $i;
                }

                break;
            }
        }

        if ($index === -1) {
            throw new \LogicException('Не удалось найти место для вставки кода в миграции');
        }

        // Вставляем новую строку
        $firstPart = array_slice($rows, 0, $index);
        $secondPart = array_slice($rows, $index);
        $fieldRow = "            {$fieldDefinition}";
        $newRows = array_merge($firstPart, [$fieldRow], $secondPart);

        // Перезаписываем файл.
        $migrationPath = $this->findMigrationFile();

        if ($migrationPath === null) {
            throw new \LogicException('Не удалось найти миграцию');
        }

        $fs = new FileSystem();
        $fs->writeFile($migrationPath, implode("\n", $newRows));
    }

    private function getMigrationsPath(): string
    {
        return base_path()
            . DIRECTORY_SEPARATOR . 'database'
            . DIRECTORY_SEPARATOR . 'migrations';
    }

    private function camelize(string $value): string
    {
        return ucfirst(strtolower($value));
    }

    private function pluralize(string $value): string
    {
        if (strlen($value) === 0) {
            return '';
        }

        return Str::plural($value);
    }

    private function depluralize(string $value): string
    {
        if (strlen($value) === 0) {
            return '';
        }

        return Str::singular($value);
    }

    private function deleteModel(string $modelNameCamelCased): void
    {
        $fs = new FileSystem();
        $fs->deleteFile($this->getModelPath($modelNameCamelCased));
    }

    private function updateModelFields(string $modelNameCamelCased): void
    {
        $rows = $this->getModelRows($modelNameCamelCased);

        // Определяем номер строки с именем класса
        $classNameRowIndex = $this->findRowStartsWith($rows, 'class ', 0);

        if ($classNameRowIndex === -1) {
            var_dump($rows);

            throw new \LogicException('Не удалось найти место для вставки PhpDoc');
        }

        $firstPart = array_slice($rows, 0, $classNameRowIndex);
        $secondPart = array_slice($rows, $classNameRowIndex);

        $rows2 = array_merge($firstPart, $this->getPropertiesPhpDocRows(), $secondPart);


        // Определяем номер строки с завершением блока класса
        $closingClassRowIndex = $this->findRowStartsWith($rows2, '}', 0);

        if ($closingClassRowIndex === -1) {
            throw new \LogicException('Не удалось найти место для вставки fillable');
        }

        $firstPart = array_slice($rows2, 0, $closingClassRowIndex);
        $secondPart = array_slice($rows2, $closingClassRowIndex);

        $rows3 = array_merge($firstPart, $this->getPropertiesFillableRows(), $secondPart);

        // Перезаписываем файл.
        $fs = new FileSystem();
        $fs->writeFile($this->getModelPath($modelNameCamelCased), implode("\n", $rows3));
    }

    private function getModelRows(string $modelNameCamelCased): array
    {
        $fs = new FileSystem();
        $content = $fs->readFile($this->getModelPath($modelNameCamelCased));

        return explode("\n", $content);
    }

    private function getModelPath(string $modelNameCamelCased): string
    {
        return $this->command->getLaravel()->basePath()
            . '/app/Models/'
            . $modelNameCamelCased . '.php';
    }

    private function getPropertiesPhpDocRows(): array
    {
        $phpDocRows = ['/**'];

        $migrationRows = $this->getMigrationRows();

        foreach ($migrationRows as $row) {
            if (!$this->isRowMigrationField($row)) {
                continue;
            }

            $type = $this->extractMirationRowPropertyType($row);
            $name = $this->extractMirationRowPropertyName($row);
            $phpDocRows []= " * @property {$type} \${$name}";
        }

        $phpDocRows []= '*/';

        return $phpDocRows;
    }

    private function getPropertiesFillableRows(): array
    {
        $fillableRows = [
            '',
            '    protected $fillable = [',
        ];

        $migrationRows = $this->getMigrationRows();

        foreach ($migrationRows as $row) {
            if (!$this->isRowMigrationField($row)) {
                continue;
            }

            $name = $this->extractMirationRowPropertyName($row);
            $fillableRows []= "        '{$name}',";
        }

        $fillableRows []= '    ];';

        return $fillableRows;
    }

    private function extractMirationRowPropertyType(string $row): string
    {
        $types = [
            [
                'php_type' => 'int',
                'migration_field_type' => 'integer',
            ],
            [
                'php_type' => 'int',
                'migration_field_type' => 'unsignedBigInteger',
            ],
            [
                'php_type' => 'int',
                'migration_field_type' => 'bigInteger',
            ],
            [
                'php_type' => 'string',
                'migration_field_type' => 'string',
            ],
            [
                'php_type' => 'string',
                'migration_field_type' => 'decimal',
            ],
            [
                'php_type' => 'string',
                'migration_field_type' => 'json',
            ],
            [
                'php_type' => 'int',
                'migration_field_type' => 'boolean',
            ],
            [
                'php_type' => 'string',
                'migration_field_type' => 'text',
            ],
            [
                'php_type' => '\DateTimeInterface',
                'migration_field_type' => 'timestamp',
            ],
        ];

        $detectedType = null;
        $migrationFieldType = $this->getBetween($row, '$table->', '(\'');

        foreach ($types as $typeInfo) {
            if ($typeInfo['migration_field_type'] === $migrationFieldType) {
                $detectedType = $typeInfo['php_type'];

                break;
            }
        }

        if ($detectedType === null) {
            return 'mixed';
        }

        $isNullable = str_contains($row, '->nullable()');

        return $detectedType . ($isNullable ? '|null' : '');
    }

    private function extractMirationRowPropertyName(string $row): string
    {
        $params = $this->getBetween($row, '(', ')');
        $paramsList = explode(',', $params);
        $firstParam = $paramsList[0] ?? '';

        return $this->getBetween($firstParam, '\'', '\'');
    }

    private function getBetween(string $line, string $before, string $after): string
    {
        $indexHead = strpos($line, $before);

        if ($indexHead === false) {
            return '';
        }

        $line = substr($line, $indexHead + strlen($before));

        $indexTail = strpos($line, $after);

        if ($indexTail === false) {
            return '';
        }

        return substr($line, 0, $indexTail);
    }

    private function isRowMigrationField(string $row): bool
    {
        return str_starts_with(trim($row), '$table->')
            && str_contains($row, '(\'')
            && str_ends_with(trim($row), ';');
    }
}
