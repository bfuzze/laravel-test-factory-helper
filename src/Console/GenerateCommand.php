<?php

namespace Mpociot\LaravelTestFactoryHelper\Console;

use Archetype\Facades\PHPFile;
use Doctrine\DBAL\Types\Type;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mpociot\LaravelTestFactoryHelper\Types\EnumType;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;


class GenerateCommand extends Command
{
    /**
     * @var Filesystem $files
     */
    protected $files;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'generate:model-factory';

    /**
     * @var string
     */
    protected $dir = 'app';

    /** @var \Illuminate\Contracts\View\Factory */
    protected $view;

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate database test factories for models';

    /**
     * @var string
     */
    protected $existingFactories = '';

    /**
     * @var array
     */
    protected $properties = array();

    /**
     * @var
     */
    protected $force;

    /**
     * @param Filesystem $files
     */
    public function __construct(Filesystem $files, $view)
    {
        parent::__construct();
        $this->files = $files;
        $this->view = $view;
    }

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        Type::addType('customEnum', EnumType::class);
        $this->dir = $this->option('dir');
        $this->force = $this->option('force');

        $models = $this->loadModels($this->argument('model'));

        foreach ($models as $model) {
            $filename = base_path() . '/database/factories/' . class_basename($model) . 'Factory.php';

            if ($this->files->exists($filename) && !$this->force) {
                $this->line('<fg=yellow>Model factory exists, use --force to overwrite:</fg=yellow> ' . $filename);

                continue;
            }

            $result = $this->generateFactory($model);

            if ($result === false) {
                continue;
            }

            $written = $this->files->put($filename, $result);
            if ($written !== false) {
                $this->line('<info>Model factory created:</info> ' . $filename);
            } else {
                $this->line('<error>Failed to create model factory:</error> ' . $filename);
            }
        }
    }


    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return array(
            array('model', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'Which models to include', array()),
        );
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        return [
            ['dir', 'D', InputOption::VALUE_OPTIONAL, 'The model directory', $this->dir],
            ['force', 'F', InputOption::VALUE_NONE, 'Overwrite any existing model factory'],
        ];
    }

    protected function generateFactory($model)
    {
        $output = '<?php' . "\n\n";

        $this->properties = [];
        if (!class_exists($model)) {
            if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                $this->error("Unable to find '$model' class");
            }
            return false;
        }

        try {
            // handle abstract classes, interfaces, ...
            $reflectionClass = new \ReflectionClass($model);

            if (!$reflectionClass->isSubclassOf('Illuminate\Database\Eloquent\Model')) {
                return false;
            }

            if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                $this->comment("Loading model '$model'");
            }

            if (!$reflectionClass->IsInstantiable()) {
                // ignore abstract class or interface
                return false;
            }

            $model = $this->laravel->make($model);

            $this->getPropertiesFromTable($model);
            $this->getPropertiesFromMethods($model);

            $output .= $this->createFactory($model);
        } catch (\Exception $e) {
            $this->error("Exception: " . $e->getMessage() . "\nCould not analyze class $model.");
        }

        return $output;
    }


    protected function loadModels($models = [])
    {
        if (!empty($models)) {
            return array_map(function ($name) {
                if (strpos($name, '\\') !== false) {
                    return $name;
                }

                return str_replace(
                    [DIRECTORY_SEPARATOR, basename($this->laravel->path()) . '\\'],
                    ['\\', $this->laravel->getNamespace()],
                    $this->dir . DIRECTORY_SEPARATOR . $name
                );
            }, $models);
        }


        $dir = base_path($this->dir);
        if (!file_exists($dir)) {
            return [];
        }

        return array_map(function (\SplFIleInfo $file) {
            return str_replace(
                [DIRECTORY_SEPARATOR, basename($this->laravel->path()) . '\\'],
                ['\\', $this->laravel->getNamespace()],
                $file->getPath() . DIRECTORY_SEPARATOR . basename($file->getFilename(), '.php')
            );
        }, $this->files->allFiles($this->dir));
    }

    /**
     * Load the properties from the database table.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     */
    protected function getPropertiesFromTable($model)
    {
        $table = $model->getConnection()->getTablePrefix() . $model->getTable();
        $schema = $model->getConnection()->getDoctrineSchemaManager($table);
        $databasePlatform = $schema->getDatabasePlatform();
        $databasePlatform->registerDoctrineTypeMapping('enum', 'customEnum');

        $platformName = $databasePlatform->getName();
        $customTypes = $this->laravel['config']->get("ide-helper.custom_db_types.{$platformName}", array());
        foreach ($customTypes as $yourTypeName => $doctrineTypeName) {
            $databasePlatform->registerDoctrineTypeMapping($yourTypeName, $doctrineTypeName);
        }

        $database = null;
        if (strpos($table, '.')) {
            list($database, $table) = explode('.', $table);
        }

        $columns = $schema->listTableColumns($table, $database);

        if ($columns) {
            foreach ($columns as $column) {
                $name = $column->getName();
                if (in_array($name, $model->getDates())) {
                    $type = 'datetime';
                } else {
                    $type = $column->getType()->getName();
                }
                if (!($model->incrementing && $model->getKeyName() === $name) &&
                    $name !== $model::CREATED_AT &&
                    $name !== $model::UPDATED_AT
                ) {
                    if (!method_exists($model, 'getDeletedAtColumn') || (method_exists($model, 'getDeletedAtColumn') && $name !== $model->getDeletedAtColumn())) {
                        $this->setProperty($name, $type, $table);
                    }
                }
            }
        }
    }


    /**
     * @param \Illuminate\Database\Eloquent\Model $model
     */
    protected function getPropertiesFromMethods($model)
    {
        $methods = get_class_methods($model);

        foreach ($methods as $method) {
            if (!Str::startsWith($method, 'get') && !method_exists('Illuminate\Database\Eloquent\Model', $method)) {
                // Use reflection to inspect the code, based on Illuminate/Support/SerializableClosure.php
                $reflection = new \ReflectionMethod($model, $method);
                $file = new \SplFileObject($reflection->getFileName());
                $file->seek($reflection->getStartLine() - 1);
                $code = '';
                while ($file->key() < $reflection->getEndLine()) {
                    $code .= $file->current();
                    $file->next();
                }
                $code = trim(preg_replace('/\s\s+/', '', $code));
                $begin = strpos($code, 'function(');
                $code = substr($code, $begin, strrpos($code, '}') - $begin + 1);
                foreach (['belongsTo'] as $relation) {
                    $search = '$this->' . $relation . '(';
                    if ($pos = stripos($code, $search)) {
                        $relationObj = $model->$method();
                        if ($relationObj instanceof Relation) {
                            $this->setProperty($relationObj->getForeignKeyName(), get_class($relationObj->getRelated()) . '::factory()->create()');
                            $relation_class = get_class($relationObj->getRelated());
                            if (!method_exists($relation_class, 'factory')) {
                                $this->output->warning("Related dependency class $relation_class does not implement HasFactory.");
                            }
                        }
                    }
                }
            }
        }

        PHPFile::load($model::class)
            ->add()->use(HasFactory::class)
            ->add()->useTrait('HasFactory')
            ->save();
    }

    /**
     * @param string $name
     * @param string|null $type
     */
    protected function setProperty($name, $type = null, $table = null)
    {
        if ($type !== null && Str::contains($type, '::factory(')) {
            $this->properties[$name] = $type;

            return;
        }

        $fakeableTypes = [
            'enum' => '$this->faker->randomElement(' . $this->enumValues($table, $name) . ')',
            'string' => '$this->faker->word',
            'text' => '$this->faker->text',
            'date' => '$this->faker->date()',
            'time' => '$this->faker->time()',
            'guid' => '$this->faker->word',
            'datetimetz' => '$this->faker->dateTime()',
            'datetime' => '$this->faker->dateTime()',
            'integer' => '$this->faker->randomNumber()',
            'bigint' => '$this->faker->randomNumber()',
            'smallint' => '$this->faker->randomNumber()',
            'decimal' => '$this->faker->randomFloat()',
            'float' => '$this->faker->randomFloat()',
            'boolean' => '$this->faker->boolean'
        ];

        $fakeableNames = [
            'city' => '$this->faker->city',
            'company' => '$this->faker->company',
            'country' => '$this->faker->country',
            'description' => '$this->faker->text',
            'email' => '$this->faker->safeEmail',
            'first_name' => '$this->faker->firstName',
            'firstname' => '$this->faker->firstName',
            'guid' => '$this->faker->uuid',
            'last_name' => '$this->faker->lastName',
            'lastname' => '$this->faker->lastName',
            'lat' => '$this->faker->latitude',
            'latitude' => '$this->faker->latitude',
            'lng' => '$this->faker->longitude',
            'longitude' => '$this->faker->longitude',
            'name' => '$this->faker->name',
            'password' => 'bcrypt($this->faker->password)',
            'phone' => '$this->faker->phoneNumber',
            'phone_number' => '$this->faker->phoneNumber',
            'postcode' => '$this->faker->postcode',
            'postal_code' => '$this->faker->postcode',
            'remember_token' => 'Str::random(10)',
            'slug' => '$this->faker->slug',
            'street' => '$this->faker->streetName',
            'address1' => '$this->faker->streetAddress',
            'address2' => '$this->faker->secondaryAddress',
            'summary' => '$this->faker->text',
            'url' => '$this->faker->url',
            'user_name' => '$this->faker->userName',
            'username' => '$this->faker->userName',
            'uuid' => '$this->faker->uuid',
            'zip' => '$this->faker->postcode',
        ];

        if (isset($fakeableNames[$name])) {
            $this->properties[$name] = $fakeableNames[$name];

            return;
        }

        if (isset($fakeableTypes[$type])) {
            $this->properties[$name] = $fakeableTypes[$type];

            return;
        }

        $this->properties[$name] = '$this->faker->word';
    }

    public static function enumValues($table, $name)
    {
        if ($table === null) {
            return "[]";
        }

        $type = DB::select(DB::raw('SHOW COLUMNS FROM ' . $table . ' WHERE Field = "' . $name . '"'))[0]->Type;

        preg_match_all("/'([^']+)'/", $type, $matches);

        $values = isset($matches[1]) ? $matches[1] : array();

        return "['" . implode("', '", $values) . "']";
    }


    /**
     * @param string $class
     * @return string
     */
    protected function createFactory($class)
    {
        $reflection = new \ReflectionClass($class);

        $short_name = $reflection->getShortName();

        $content = $this->view->make('test-factory-helper::factory', [
            'reflection' => $reflection,
            'properties' => $this->properties,
            'factory_class_name'  => "{$short_name}Factory",
            'model_class' => $reflection->getName(),
            'model_class_short' => $short_name,
        ])->render();

        return $content;
    }

}
