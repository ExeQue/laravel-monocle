<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses\Packages\GraphQL\Lighthouse;

use ExeQue\Monocle\Lenses\Packages\GraphQL\Lighthouse\SchemaFiles;
use Illuminate\Filesystem\Filesystem;

covers(SchemaFiles::class);

describe(SchemaFiles::class, function (): void {
    it('finds fields across imported files, globs and type extensions', function (): void {
        $dir = sys_get_temp_dir().'/monocle-schema-'.bin2hex(random_bytes(4));
        mkdir($dir.'/parts', recursive: true);
        file_put_contents($dir.'/schema.graphql', "#import parts/*.graphql\n#import schema.graphql\n\ntype Query {\n  users: [String!]!\n}\n");
        file_put_contents($dir.'/parts/a.graphql', "extend type Query {\n  \"\"\"\n  posts: not this one\n  \"\"\"\n  posts(first: Int): [String!]!\n}\n");
        file_put_contents($dir.'/parts/b.graphql', "#import a.graphql\ntype Mutation { inline: Int }\ntype Mutation {\n  save: Boolean\n}\n");
        config(['lighthouse.schema_path' => $dir.'/schema.graphql']);

        try {
            $files = new SchemaFiles();

            expect($files->find('Query', 'users'))->toBe(realpath($dir).'/schema.graphql:5')
                ->and($files->find('Query', 'posts'))->toBe(realpath($dir).'/parts/a.graphql:5')
                ->and($files->find('Mutation', 'save'))->toBe(realpath($dir).'/parts/b.graphql:4')
                ->and($files->find('Query', 'missing'))->toBeNull();
        } finally {
            new Filesystem()->deleteDirectory($dir);
        }
    });

    it('finds nothing without a schema file', function (): void {
        config(['lighthouse.schema_path' => '/nowhere/schema.graphql']);

        expect(new SchemaFiles()->find('Query', 'users'))->toBeNull()
            ->and(new SchemaFiles()->root())->toBeNull();
    });

    it('shortens paths inside the application', function (): void {
        $file = base_path('monocle-schema-'.bin2hex(random_bytes(4)).'.graphql');
        file_put_contents($file, "type Query {\n  me: String\n}\n");
        config(['lighthouse.schema_path' => $file]);

        try {
            expect(new SchemaFiles()->find('Query', 'me'))->toBe(basename($file).':2')
                ->and(new SchemaFiles()->root())->toBe(basename($file));
        } finally {
            unlink($file);
        }
    });
});
