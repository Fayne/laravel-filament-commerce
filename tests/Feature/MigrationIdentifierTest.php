<?php

test('commerce composite constraint names fit the MySQL identifier limit', function (): void {
    foreach (glob(__DIR__.'/../../database/migrations/*.php') as $file) {
        $table = '';
        foreach (file($file) as $line) {
            if (preg_match("/Schema::(?:create|table)\('([^']+)'/", $line, $match)) {
                $table = $match[1];
            }
            if (preg_match("/\\\$table->(unique|index|foreign)\((\[[^]]+\])(?:, '([^']+)')?\)/", $line, $match)) {
                preg_match_all("/'([^']+)'/", $match[2], $columns);
                $name = $match[3] ?? $table.'_'.implode('_', $columns[1]).'_'.$match[1];
                expect(strlen($name), basename($file).': '.$name)->toBeLessThanOrEqual(64);
            }
        }
    }
});
