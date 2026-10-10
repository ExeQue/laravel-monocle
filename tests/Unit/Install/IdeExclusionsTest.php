<?php

declare(strict_types=1);

use ExeQue\Monocle\Install\IdeExclusions;

covers(IdeExclusions::class);

describe(IdeExclusions::class, function (): void {
    beforeEach(function (): void {
        $this->dir = sys_get_temp_dir().'/monocle-ide-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->ide = new IdeExclusions();
    });

    afterEach(function (): void {
        exec('rm -rf '.escapeshellarg($this->dir));
    });

    $module = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <module type="WEB_MODULE" version="4">
          <component name="NewModuleRootManager">
            <content url="file://$MODULE_DIR$">
              <sourceFolder url="file://$MODULE_DIR$/app" isTestSource="false" packagePrefix="App\" />
              <excludeFolder url="file://$MODULE_DIR$/storage/app" />
            </content>
            <orderEntry type="inheritedJdk" />
          </component>
        </module>
        XML;

    it('does nothing when the project has no IDE settings', function (): void {
        expect($this->ide->apply($this->dir, 'storage/monocle'))->toBe([]);
    });

    describe('PhpStorm', function () use ($module): void {
        it('adds an excluded folder to the module modules.xml points at, once', function () use ($module): void {
            mkdir($this->dir.'/.idea');
            file_put_contents($this->dir.'/.idea/other.iml', $module);
            file_put_contents($this->dir.'/.idea/app.iml', $module);
            file_put_contents($this->dir.'/.idea/modules.xml', '<module fileurl="file://$PROJECT_DIR$/.idea/app.iml" filepath="$PROJECT_DIR$/.idea/app.iml" />');

            expect($this->ide->apply($this->dir, '/storage/monocle/'))->toBe(['PhpStorm (.idea/app.iml)' => 'updated']);

            $written = (string) file_get_contents($this->dir.'/.idea/app.iml');

            expect($written)->toContain("      <excludeFolder url=\"file://\$MODULE_DIR\$/storage/app\" />\n      <excludeFolder url=\"file://\$MODULE_DIR\$/storage/monocle\" />\n    </content>")
                ->and(file_get_contents($this->dir.'/.idea/other.iml'))->toBe($module)
                ->and(simplexml_load_string($written))->not->toBeFalse()
                ->and($this->ide->apply($this->dir, 'storage/monocle'))->toBe(['PhpStorm (.idea/app.iml)' => 'unchanged']);
        });

        it('expands a self-closing content root and falls back to the only module file', function (): void {
            mkdir($this->dir.'/.idea');
            file_put_contents($this->dir.'/.idea/project.iml', "<module>\n  <component>\n    <content url=\"file://\$MODULE_DIR\$\" />\n  </component>\n</module>\n");

            expect($this->ide->apply($this->dir, 'storage/monocle'))->toBe(['PhpStorm (.idea/project.iml)' => 'updated'])
                ->and(file_get_contents($this->dir.'/.idea/project.iml'))->toContain("    <content url=\"file://\$MODULE_DIR\$\">\n      <excludeFolder url=\"file://\$MODULE_DIR\$/storage/monocle\" />\n    </content>");
        });

        it('reports when there is nothing to add to', function (): void {
            mkdir($this->dir.'/.idea');

            expect($this->ide->apply($this->dir, 'storage/monocle'))->toBe(['PhpStorm (.idea)' => 'skipped: no project module found']);

            file_put_contents($this->dir.'/.idea/project.iml', '<module><component /></module>');

            expect($this->ide->apply($this->dir, 'storage/monocle'))->toBe(['PhpStorm (.idea/project.iml)' => 'skipped: no project content root found']);
        });

        it('changes nothing in dry-run mode', function () use ($module): void {
            mkdir($this->dir.'/.idea');
            file_put_contents($this->dir.'/.idea/app.iml', $module);

            expect($this->ide->apply($this->dir, 'storage/monocle', dryRun: true))->toBe(['PhpStorm (.idea/app.iml)' => 'would be updated'])
                ->and(file_get_contents($this->dir.'/.idea/app.iml'))->toBe($module);
        });
    });

    describe('VS Code', function (): void {
        it('creates settings with search and watcher exclusions', function (): void {
            mkdir($this->dir.'/.vscode');

            expect($this->ide->apply($this->dir, 'storage/monocle'))->toBe(['VS Code (.vscode/settings.json)' => 'created']);

            $settings = json_decode((string) file_get_contents($this->dir.'/.vscode/settings.json'), true);

            expect($settings)->toBe([
                'search.exclude' => ['storage/monocle/**' => true],
                'files.watcherExclude' => ['**/storage/monocle/**' => true],
            ])->and($this->ide->apply($this->dir, 'storage/monocle'))->toBe(['VS Code (.vscode/settings.json)' => 'unchanged']);
        });

        it('merges into existing settings, keeping indentation', function (): void {
            mkdir($this->dir.'/.vscode');
            file_put_contents($this->dir.'/.vscode/settings.json', "{\n  \"editor.tabSize\": 4,\n  \"search.exclude\": {\n    \"node_modules\": true\n  }\n}\n");

            expect($this->ide->apply($this->dir, 'storage/monocle'))->toBe(['VS Code (.vscode/settings.json)' => 'updated']);

            $written = (string) file_get_contents($this->dir.'/.vscode/settings.json');
            $settings = json_decode($written, true);

            expect($settings['editor.tabSize'])->toBe(4)
                ->and($settings['search.exclude'])->toBe(['node_modules' => true, 'storage/monocle/**' => true])
                ->and($written)->toContain("\n  \"editor.tabSize\": 4,");
        });

        it('leaves settings with comments alone', function (): void {
            mkdir($this->dir.'/.vscode');
            $original = "{\n  // my settings\n  \"editor.tabSize\": 4\n}\n";
            file_put_contents($this->dir.'/.vscode/settings.json', $original);

            expect($this->ide->apply($this->dir, 'storage/monocle')['VS Code (.vscode/settings.json)'])->toStartWith('skipped: settings.json has comments')
                ->and(file_get_contents($this->dir.'/.vscode/settings.json'))->toBe($original);
        });

        it('writes nothing in dry-run mode', function (): void {
            mkdir($this->dir.'/.vscode');

            expect($this->ide->apply($this->dir, 'storage/monocle', dryRun: true))->toBe(['VS Code (.vscode/settings.json)' => 'would be created'])
                ->and(file_exists($this->dir.'/.vscode/settings.json'))->toBeFalse();
        });
    });
});
