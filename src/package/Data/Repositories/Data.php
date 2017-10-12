<?php

namespace PragmaRX\TestsWatcher\Package\Data\Repositories;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB as Database;
use PragmaRX\TestsWatcher\Package\Entities\Project;
use PragmaRX\TestsWatcher\Package\Entities\Queue;
use PragmaRX\TestsWatcher\Package\Entities\Run;
use PragmaRX\TestsWatcher\Package\Entities\Suite;
use PragmaRX\TestsWatcher\Package\Entities\Test;
use PragmaRX\TestsWatcher\Package\Entities\Tester;
use PragmaRX\TestsWatcher\Package\Support\Notifier;
use SensioLabs\AnsiConverter\AnsiToHtmlConverter;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

class Data
{
    /**
     * Internal constants.
     */
    const STATE_IDLE = 'idle';

    const STATE_QUEUED = 'queued';

    const STATE_OK = 'ok';

    const STATE_FAILED = 'failed';

    const STATE_RUNNING = 'running';

    protected $ansiConverter;

    /**
     * @var Notifier
     */
    protected $notifier;

    /**
     * @var \Illuminate\Support\Collection
     */
    protected $messages;

    /**
     * Data constructor.
     *
     * @param Notifier $notifier
     */
    public function __construct(Notifier $notifier)
    {
        $this->ansiConverter = new AnsiToHtmlConverter();

        $this->notifier = $notifier;

        $this->messages = collect();
    }

    /**
     * Carriage return to <br>.
     *
     * @param $lines
     *
     * @return string
     */
    protected function CRToBr($lines)
    {
        return str_replace("\n", '<br>', $lines);
    }

    /**
     * Add a message to the list.
     *
     * @param $type
     * @param $body
     *
     * @internal param $string
     * @internal param $string1
     */
    protected function addMessage($type, $body)
    {
        $this->messages->push(['type' => $type, 'body' => $body]);
    }

    /**
     * <br> to carriage return.
     *
     * @param $lines
     *
     * @return string
     */
    protected function brToCR($lines)
    {
        return str_replace('<br>', "\n", $lines);
    }

    /**
     * Create link to call the editor for a file.
     *
     * @param $test
     * @param $fileName
     * @param $line
     * @param $occurrence
     *
     * @return string
     */
    protected function createLinkToEditFile($test, $fileName, $line, $occurrence)
    {
        if (!$this->fileExistsOnTest($fileName, $test)) {
            return $line[$occurrence];
        }

        $fileName = base64_encode($fileName);

        $tag = sprintf(
            '<a href="javascript:jQuery.get(\'%s\');" class="file">%s</a>',
            route(
                'tests-watcher.file.edit',
                [
                    'filename' => $fileName,
                    'suite_id' => $test->suite->id,
                    'line'     => $line[2],
                ]
            ),
            $line[$occurrence]
        );

        return $tag;
    }

    /**
     * Create links.
     *
     * @param $lines
     * @param $matches
     *
     * @return mixed
     */
    protected function createLinks($lines, $matches, $test)
    {
        foreach ($matches as $line) {
            if (count($line) > 0 && count($line[0]) > 0) {
                $occurence = strpos($lines, $line[0]) === false ? 1 : 0;

                $lines = str_replace(
                    $line[$occurence],
                    $this->createLinkToEditFile($test, $line[1], $line, $occurence),
                    $lines
                );
            }
        }

        return $lines;
    }

    /**
     * Create or update a tester.
     *
     * @param $name
     * @param $data
     */
    public function createOrUpdateTester($name, $data)
    {
        Tester::updateOrCreate(
            ['name' => $name],
            [
                'command'                    => $data['command'],
                'output_folder'              => array_get($data, 'output_folder'),
                'output_html_fail_extension' => array_get($data, 'output_html_fail_extension'),
                'output_png_fail_extension'  => array_get($data, 'output_png_fail_extension'),
                'require_tee'                => array_get($data, 'require_tee', false),
                'require_script'             => array_get($data, 'require_script', false),
                'error_pattern'              => array_get($data, 'error_pattern'),
                'env'                        => array_get($data, 'env'),
            ]
        );
    }

    /**
     * Create or update a project.
     *
     * @param $name
     * @param $path
     * @param $tests_path
     *
     * @return static
     */
    public function createOrUpdateProject($name, $path, $tests_path)
    {
        return Project::updateOrCreate(['name' => $name], ['path' => $path, 'tests_path' => $tests_path]);
    }

    /**
     * Create or update a suite.
     *
     * @param $name
     * @param $project_id
     * @param $suite_data
     *
     * @return Suite|null|boolean
     */
    public function createOrUpdateSuite($name, $project_id, $suite_data)
    {
        $project_id = $project_id instanceof Project ? $project_id->id : $project_id;

        if (is_null($tester = Tester::where('name', $suite_data['tester'])->first())) {
            $this->addMessage('error', "Tester {$suite_data['tester']} not found.");

            return false;
        }

        return Suite::updateOrCreate(
            [
                'name' => $name,
                'project_id' => $project_id,
            ],
            [
                'tester_id'       => $tester->id,
                'tests_path'      => array_get($suite_data, 'tests_path'),
                'command_options' => array_get($suite_data, 'command_options'),
                'file_mask'       => array_get($suite_data, 'file_mask'),
                'retries'         => array_get($suite_data, 'retries'),
                'editor'          => array_get($suite_data, 'editor'),
            ]
        );
    }

    /**
     * Find source code references.
     *
     * Must find
     *
     *   at Object..test (resources/assets/js/tests/example.spec.js:4:23
     *
     *   (resources/assets/js/tests/example.spec.js:4
     *
     *   /resources/assets/js/tests/example.php:449
     *
     * @param $lines
     * @param $test
     *
     * @return mixed
     */
    protected function findSourceCodeReferences($lines, $test)
    {
        preg_match_all(
            config('ci.regex_file_matcher'),
            strip_tags($this->brToCR($lines)),
            $matches,
            PREG_SET_ORDER
        );

        return array_filter($matches);
    }

    /**
     * Find test by filename and suite.
     *
     * @param $file
     * @param $suite
     *
     * @return mixed
     */
    protected function findTestByFileAndSuite($file, $suite)
    {
        $exists = Test::where('name', $file->getRelativePathname())
                      ->where('suite_id', $suite->id)
                      ->first();

        return $exists;
    }

    /**
     * Find suite by project and name
     *
     * @param $name
     * @param $project_id
     *
     * @return \PragmaRX\TestsWatcher\Package\Entities\Suite|null
     */
    public function findSuiteByNameAndProject($name, $project_id)
    {
        return Suite::where('name', $name)
                ->where('project_id', $project_id)
                ->first();
    }

    /**
     * Get the default editor binary.
     */
    protected function getDefaultEditorBinary()
    {
        if (is_null($default = collect(config('ci.editors'))->where('default', true)->first())) {
            die('FATAL ERROR: default editor not configured');
        }

        return $default['bin'];
    }

    /**
     * Get the editor command.
     *
     * @param $suite
     *
     * @return string
     */
    protected function getEditorBinary($suite)
    {
        if (empty($suite) || is_null($bin = config("ci.editors.{$suite->editor}.bin"))) {
            return $this->getDefaultEditorBinary();
        }

        return $bin;
    }

    /**
     * Generates data for the javascript client.
     */
    public function getJavascriptClientData()
    {
        $data = [
            'url_prefix' => config('ci.url_prefix'),

            'project_id' => request()->get('project_id'),

            'test_id' => request()->get('test_id'),
        ];

        return json_encode($data);
    }

    /**
     * Get all messages.
     *
     * @return Collection
     */
    public function getMessages()
    {
        return $this->messages;
    }

    /**
     * Get a list of png files to store in database.
     *
     * @param $test
     * @param $log
     *
     * @return null|string
     */
    protected function getScreenshots($test, $log)
    {
        $screenshots = $test->suite->tester->name !== 'dusk'
            ? $this->getOutput($test, $test->suite->tester->output_folder, $test->suite->tester->output_png_fail_extension)
            : $this->parseDuskScreenshots($log, $test->suite->tester->output_folder);

        if (is_null($screenshots)) {
            return;
        }

        return json_encode((array) $screenshots);
    }

    /**
     * Get all suites.
     *
     * @return \Illuminate\Database\Eloquent\Collection|static[]
     */
    public function getSuites()
    {
        return Suite::all();
    }

    /**
     * Find project by id.
     *
     * @return \PragmaRX\TestsWatcher\Package\Entities\Project|null
     */
    public function findProjectById($id)
    {
        return Project::find($id);
    }

    /**
     * Find suite by id.
     *
     * @return \PragmaRX\TestsWatcher\Package\Entities\Suite|null
     */
    public function findSuiteById($id)
    {
        return Suite::find($id);
    }

    /**
     * Create or update a test.
     *
     * @param \Symfony\Component\Finder\SplFileInfo         $file
     * @param \PragmaRX\TestsWatcher\Package\Entities\Suite $suite
     */
    public function createOrUpdateTest($file, $suite)
    {
        $test = Test::updateOrCreate(
            [
                'sha1' => sha1_file($file->getRealPath()),
            ],
            [
                'path'     => $file->getPath(),
                'name'     => $file->getFilename(),
                'suite_id' => $suite->id,
            ]
        );

        if ($this->findTestByFileAndSuite($file, $suite)) {
            $this->addTestToQueue($test);
        }
    }

    /**
     * Get test info.
     *
     * @param $test
     *
     * @return array
     */
    protected function getTestInfo($test)
    {
        $run = Run::where('test_id', $test->id)->orderBy('created_at', 'desc')->first();

        return [
            'id'            => $test->id,
            'project_name'  => $test->suite->project->name,
            'project_id'    => $test->suite->project->id,
            'path'          => $test->path.DIRECTORY_SEPARATOR,
            'name'          => $test->name,
            'edit_file_url' => $this->makeEditFileUrl($test),
            'updated_at'    => $test->updated_at->diffForHumans(),
            'state'         => $test->state,
            'enabled'       => $test->enabled,

            'run'         => $run,
            'notified_at' => is_null($run) ? null : $run->notified_at,
            'log'         => is_null($run) ? null : $run->log,
            'html'        => is_null($run) ? null : $run->html,
            'image'       => is_null($run) ? null : $run->png,
            'time'        => is_null($run) ? '' : (is_null($run->started_at) ? '' : $this->removeBefore($run->started_at->diffForHumans($run->ended_at))),
        ];
    }

    /**
     * Check if the class is abstract.
     *
     * @param $file
     *
     * @return bool
     */
    protected function isAbstractClass($file)
    {
        return (bool) preg_match(
            '/^abstract\s+class[A-Za-z0-9_\s]{1,100}{/im',
            file_get_contents($file)
        );
    }

    /**
     * Check if the file is testable.
     *
     * @param $file
     *
     * @return bool
     */
    protected function isTestable($file)
    {
        return ends_with($file, '.php')
            ? !$this->isAbstractClass($file)
            : true;
    }

    /**
     * Create links for files.
     *
     * @param $lines
     *
     * @return string
     */
    protected function linkFiles($lines, $test)
    {
        $matches = $this->findSourceCodeReferences($lines, $test);

        if (count($matches) != 0) {
            $lines = $this->createLinks($lines, $matches, $test);
        }

        return $this->CRToBr($lines);
    }

    /**
     * @param $test
     *
     * @return string
     */
    protected function makeEditFileUrl($test)
    {
        return route(
            'tests-watcher.file.edit',
            [
                'filename' => base64_encode($test->path.DIRECTORY_SEPARATOR.$test->name),
                'suite_id' => $test->suite->id,
            ]
        );
    }

    /**
     * Notify users.
     *
     * @param $project_id
     */
    public function notify($project_id)
    {
        $this->notifier->notifyViaChannels(
            $this->getTests($project_id)->reject(function ($item) {
                return $item['state'] != 'failed' && is_null($item['notified_at']);
            })
        );
    }

    /**
     * Generate a lista of screenshots.
     *
     * @param $log
     * @param $folder
     *
     * @return array|null
     */
    protected function parseDuskScreenshots($log, $folder)
    {
        preg_match_all('/([0-9]\)+\s.+::)(.*)/', $log, $matches, PREG_SET_ORDER);

        $result = [];

        foreach ($matches as $line) {
            $name = str_replace("\r", '', $line[2]);

            $result[] = $folder.DIRECTORY_SEPARATOR."failure-{$name}-0.png";
        }

        return count($result) == 0 ? null : $result;
    }

    /**
     * Remove before word.
     *
     * @param $diff
     *
     * @return mixed
     */
    protected function removeBefore($diff)
    {
        return str_replace('before', '', $diff);
    }

    /**
     * Remove suites that are not in present in config.
     *
     * @param $suites
     * @param $project
     */
    public function removeMissingSuites($suites, $project)
    {
        Suite::where('project_id', $project->id)->whereNotIn('name', collect($suites)->keys())->each(function($suite) {
            $suite->delete();
        });
    }

    /**
     * Properly render HTML source code.
     *
     * @param string
     *
     * @return string
     */
    protected function renderHtml($contents)
    {
        return nl2br(
            htmlentities($contents)
        );
    }

    /**
     * Reset a test to idle state.
     *
     * @param $test
     */
    protected function resetTest($test)
    {
        Queue::where('test_id', $test->id)->delete();

        $test->state = self::STATE_IDLE;

        $test->timestamps = false;

        $test->save();
    }

    /**
     * Sync all tests.
     *
     * @param $exclusions
     */
    public function syncTests($exclusions)
    {
        foreach ($this->getSuites() as $suite) {
            $this->syncTestsForSuite($suite, $exclusions);
        }
    }

    /**
     * Sync all tests for a particular suite.
     *
     * @param $suite
     * @param $exclusions
     */
    protected function syncTestsForSuite($suite, $exclusions)
    {
        $files = $this->getAllFilesFromSuite($suite);

        foreach ($files as $file) {
            if (!$this->isExcluded($exclusions, null, $file) && $this->isTestable($file->getRealPath())) {
                $this->createOrUpdateTest($file, $suite);
            } else {
                // If the test already exists, delete it.
                //
                if ($test = $this->findTestByNameAndSuite($file, $suite)) {
                    $test->delete();
                }
            }
        }

        foreach ($suite->tests as $test) {
            if (!file_exists($path = $test->fullPath)) {
                $test->delete();
            }
        }
    }

    /**
     * Get all files from a suite.
     *
     * @param $suite
     *
     * @return array
     */
    protected function getAllFilesFromSuite($suite)
    {
        if (!file_exists($suite->testsFullPath)) {
            die('FATAL ERROR: directory not found: '.$suite->testsFullPath.'.');
        }

        $files = Finder::create()->files()->in($suite->testsFullPath);

        if ($suite->file_mask) {
            $files->name($suite->file_mask);
        }

        return iterator_to_array($files, false);
    }

    /**
     * Check if a file is a test file.
     *
     * @param $path
     *
     * @return \___PHPSTORM_HELPERS\static|bool|mixed
     */
    public function isTestFile($path)
    {
        if (file_exists($path)) {
            foreach (Test::all() as $test) {
                if ($test->fullPath == $path) {
                    return $test;
                }
            }
        }

        return false;
    }

    /**
     * Queue all tests.
     */
    public function queueAllTests()
    {
        $this->showProgress('QUEUE: adding tests to queue...');

        foreach (Test::all() as $test) {
            $this->addTestToQueue($test);
        }
    }

    /**
     * Queue all tests from a particular suite.
     *
     * @param $suite_id
     */
    public function queueTestsForSuite($suite_id)
    {
        $tests = Test::where('suite_id', $suite_id)->get();

        foreach ($tests as $test) {
            $this->addTestToQueue($test);
        }
    }

    /**
     * Add a test to the queue.
     *
     * @param $test
     * @param bool $force
     */
    public function addTestToQueue($test, $force = false)
    {
        if ($test->enabled && $test->suite->project->enabled && !$this->isEnqueued($test)) {
            $test->updateSha1();

            Queue::updateOrCreate(['test_id' => $test->id]);

            if ($force || !in_array($test->state, [self::STATE_RUNNING, self::STATE_QUEUED])) {
                $test->state = self::STATE_QUEUED;
                $test->timestamps = false;
                $test->save();
            }
        }
    }

    /**
     * Get a test from the queue.
     *
     * @return \PragmaRX\TestsWatcher\Package\Entities\Test|null
     */
    public function getNextTestFromQueue()
    {
        $query = Queue::join('ci_tests', 'ci_tests.id', '=', 'ci_queue.test_id')
            ->where('ci_tests.enabled', true)
            ->where('ci_tests.state', '!=', static::STATE_RUNNING);

        if (!$queue = $query->first()) {
            return;
        }

        return $queue->test;
    }

    /**
     * Store the test result.
     *
     * @param $test
     * @param $lines
     * @param $ok
     * @param $startedAt
     * @param $endedAt
     *
     * @return mixed
     */
    public function storeTestResult($test, $lines, $ok, $startedAt, $endedAt)
    {
        if (!$this->testExists($test)) {
            return false;
        }

        $run = Run::create([
            'test_id'     => $test->id,
            'was_ok'      => $ok,
            'log'         => $this->formatLog($lines, $test) ?: '(empty)',
            'html'        => $this->getOutput($test, $test->suite->tester->output_folder, $test->suite->tester->output_html_fail_extension),
            'screenshots' => $this->getScreenshots($test, $lines),
            'started_at'  => $startedAt,
            'ended_at'    => $endedAt,
        ]);

        $test->state = $ok ? self::STATE_OK : self::STATE_FAILED;

        $test->last_run_id = $run->id;

        $test->save();

        $this->removeTestFromQueue($test);

        return $ok;
    }

    /**
     * Remove test from que run queue.
     *
     * @param $test
     *
     * @return mixed
     */
    protected function removeTestFromQueue($test)
    {
        Queue::where('test_id', $test->id)->delete();

        return $test;
    }

    /**
     * Mark a test as being running.
     *
     * @param $test
     */
    public function markTestAsRunning($test)
    {
        $test->state = self::STATE_RUNNING;

        $test->save();
    }

    /**
     * Delete unavailable testers.
     *
     * @param $testers
     */
    public function deleteUnavailableTesters($testers)
    {
        foreach (Tester::all() as $tester) {
            if (!in_array($tester->name, $testers)) {
                $tester->delete();
            }
        }
    }

    /**
     * Delete unavailable projects.
     *
     * @param $projects
     */
    public function deleteUnavailableProjects($projects)
    {
        foreach (Project::all() as $project) {
            if (!in_array($project->name, $projects)) {
                $project->delete();
            }
        }
    }

    /**
     * Check if a path is excluded.
     *
     * @param $exclusions
     * @param $path
     * @param string $file
     *
     * @return bool
     */
    public function isExcluded($exclusions, $path, $file = '')
    {
        if ($file) {
            if (!$file instanceof SplFileInfo) {
                $path = make_path([$path, $file]);
            } else {
                $path = $file->getPathname();
            }
        }

        foreach ($exclusions ?: [] as $excluded) {
            if (starts_with($path, $excluded)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find a test by name and suite.
     *
     * @param $suite
     * @param $file
     *
     * @return mixed
     */
    protected function findTestByNameAndSuite($file, $suite)
    {
        return Test::where('name', $file->getRelativePathname())->where('suite_id', $suite->id)->first();
    }

    /**
     * Get all tests.
     *
     * @param null $project_id
     *
     * @return array
     */
    public function getTests($project_id = null)
    {
        $tests = [];

        $order = "(case
						when state = 'running' then 1
						when state = 'failed' then 2
						when state = 'queued' then 3
						when state = 'ok' then 4
						when state = 'idle' then 5
			        end) asc,

			        updated_at desc";

        $query = Test::select('ci_tests.*')
                    ->join('ci_suites', 'ci_suites.id', '=', 'suite_id')
                    ->orderByRaw($order);

        if ($project_id) {
            $query->where('project_id', $project_id);
        }

        foreach ($query->get() as $test) {
            $tests[] = $this->getTestInfo($test);
        }

        return collect($tests);
    }

    /**
     * Get all projects.
     *
     * @return \Illuminate\Database\Eloquent\Collection|static[]
     */
    public function getProjects()
    {
        return Project::all()->map(function ($item) {
            if (!isset($item['tests'])) {
                $item['tests'] = [];
            }

            return $item;
        });
    }

    /**
     * Format output log.
     *
     * @param $log
     *
     * @return mixed|string
     */
    protected function formatLog($log, $test)
    {
        return !empty($log)
            ? $this->linkFiles($this->ansi2Html($log), $test)
            : $log;
    }

    /**
     * Convert output ansi chars to html.
     *
     * @param $log
     *
     * @return mixed|string
     */
    protected function ansi2Html($log)
    {
        $string = html_entity_decode(
            $this->ansiConverter->convert($log)
        );

        $string = str_replace("\r\n", '<br>', $string);

        $string = str_replace("\n", '<br>', $string);

        return $string;
    }

    /**
     * Remove ansi codes from string.
     *
     * @param $string
     *
     * @return string
     */
    public function removeAnsiCodes($string)
    {
        return strip_tags(
            $this->ansi2Html($string)
        );
    }

    /**
     * Enable tests.
     *
     * @param $enable
     * @param $project_id
     * @param null $test_id
     *
     * @return bool
     */
    public function enableTests($enable, $project_id, $test_id)
    {
        $enable = is_bool($enable) ? $enable : ($enable === 'true');

        $tests = $this->queryTests($project_id, $test_id == 'all' ? null : $test_id)->get();

        foreach ($tests as $test) {
            $this->enableTest($enable, $test);
        }

        return $enable;
    }

    /**
     * Enable tests.
     *
     * @param $enable
     * @param $project_id
     *
     * @return bool
     */
    public function enableProjects($enable, $project_id)
    {
        $enable = $enable === 'true';

        $projects = $project_id == 'all'
            ? Project::all()
            : Project::where('id', $project_id)->get();

        foreach ($projects as $test) {
            $this->enableProject($enable, $test);
        }

        return $enable;
    }

    /**
     * Run a test.
     *
     * @param $test
     * @param bool $force
     */
    public function runTest($test, $force = false)
    {
        if (!$test instanceof Test) {
            $test = Test::find($test);
        }

        $this->addTestToQueue($test, $force);
    }

    /**
     * Enable a test.
     *
     * @param $enable
     * @param \PragmaRX\TestsWatcher\Package\Entities\Test $test
     */
    protected function enableTest($enable, $test)
    {
        $test->timestamps = false;

        $test->enabled = $enable;

        $test->save();

        if (!$enable) {
            $this->removeTestFromQueue($test);

            return;
        }

        if ($test->state !== self::STATE_OK) {
            $this->addTestToQueue($test);
        }
    }

    /**
     * Enable a test.
     *
     * @param $enable
     * @param \PragmaRX\TestsWatcher\Package\Entities\Project $project
     */
    protected function enableProject($enable, $project)
    {
        $project->timestamps = false;

        $project->enabled = $enable;

        $project->save();
    }

    /**
     * Get the test output.
     *
     * @param $test
     * @param $outputFolder
     * @param $extension
     *
     * @return null|string
     */
    protected function getOutput($test, $outputFolder, $extension)
    {
        if (empty($outputFolder)) {
            return;
        }

        $file = make_path([
            make_path([$test->suite->project->path, $outputFolder]),
            str_replace(['.php', '::', '\\', '/'], ['', '.', '', ''], $test->name).$extension,
        ]);

        return file_exists($file) ? $this->renderHtml(file_get_contents($file)) : null;
    }

    /**
     * Encode a image or html for database storage.
     *
     * @param $file
     *
     * @return bool|mixed|string
     */
    protected function encodeFile($file)
    {
        $type = pathinfo($file, PATHINFO_EXTENSION);

        $data = file_get_contents($file);

        if ($type == 'html') {
            return $data;
        }

        return 'data:image/'.$type.';base64,'.base64_encode($data);
    }

    /**
     * Is the test in the queue?
     *
     * @param $test
     *
     * @return bool
     */
    public function isEnqueued($test)
    {
        return
            $test->state == self::STATE_QUEUED
            &&
            Queue::where('test_id', $test->id)->first();
    }

    /**
     * Get all suites for a path.
     *
     * @param $path
     *
     * @return mixed
     */
    public function getSuitesForPath($path)
    {
        $projects = $this->getProjects();

        // Reduce the collection of projects by those whose path properties
        // (should be only 1) are contained in the fullpath of our
        // changed file
        $filtered_projects = $projects->filter(function ($project) use ($path) {
            return substr_count($path, $project->path) > 0;
        });

        // Get filtered projects dependencies
        $depends = $projects->filter(function ($project) use ($filtered_projects) {
            if (!is_null($depends = config("ci.projects.{$project->name}.depends"))) {
                return collect($depends)->filter(function ($item) use ($filtered_projects) {
                    return !is_null($filtered_projects->where('name', $item)->first());
                });
            }

            return false;
        });

        // At this point we have (hopefully only 1) project. Now we need
        // the suite(s) associated with the project.
        return Suite::whereIn('project_id', $filtered_projects->merge($depends)->pluck('id'))
                ->get();
    }

    /**
     * Run all tests or projects tests.
     *
     * @param null $project_id
     */
    public function runAll($project_id = null)
    {
        $tests = $this->queryTests($project_id)->get();

        foreach ($tests as $test) {
            $this->enableTest(true, $test);

            // Force test to the queue
            $this->runTest($test, true);
        }
    }

    /**
     * Query tests.
     *
     * @param $project_id
     * @param $test_id
     *
     * @return mixed
     */
    protected function queryTests($project_id = null, $test_id = null)
    {
        $query = Test::select('ci_tests.*')
                    ->join('ci_suites', 'ci_suites.id', '=', 'ci_tests.suite_id');

        if ($project_id) {
            $query->where('ci_suites.project_id', $project_id);
        }

        if ($test_id) {
            $query->where('ci_tests.id', $test_id);
        }

        return $query;
    }

    /**
     * Run all tests or projects tests.
     *
     * @param null $project_id
     */
    public function reset($project_id = null)
    {
        foreach ($this->queryTests($project_id)->get() as $test) {
            $this->resetTest($test);
        }
    }

    /**
     * Delete all from runs table.
     */
    public function clearRuns()
    {
        Database::statement('delete from ci_runs');
    }

    /**
     * Mark tests as notified.
     *
     * @param $tests
     */
    public function markTestsAsNotified($tests)
    {
        $tests->each(function ($test) {
            $test['run']->notified_at = Carbon::now();

            $test['run']->save();
        });
    }

    /**
     * Check if the test exists.
     *
     * @param $test
     *
     * @return bool
     */
    protected function testExists($test)
    {
        return !is_null(Test::find($test->id));
    }

    /**
     * Make open file command.
     *
     * @param $file
     * @param $line
     * @param int $suite_id
     *
     * @return string
     */
    public function makeEditFileCommand($file, $line, $suite_id)
    {
        $suite = $this->findSuiteById($suite_id);

        $file = $this->addProjectRootPath(
            base64_decode($file),
            $suite
        );

        return str_replace(
            ['{$file}', '{$line}'],
            [$file, $line],
            $this->getEditorBinary($suite)
        );
    }

    /**
     * Check if a file exists for a particular test.
     *
     * @param $filename
     * @param $test
     *
     * @return bool
     */
    public function fileExistsOnTest($filename, $test)
    {
        return file_exists(
            $this->addProjectRootPath($filename, $test->suite)
        );
    }

    /**
     * Add project root to path.
     *
     * @param $fileName
     * @param $suite
     *
     * @return string
     */
    public function addProjectRootPath($fileName, $suite)
    {
        if (starts_with($fileName, DIRECTORY_SEPARATOR) || empty($suite)) {
            return $fileName;
        }

        return $suite->project->path.DIRECTORY_SEPARATOR.$fileName;
    }
}