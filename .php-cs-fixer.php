<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/tests');

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
        '@PHP71Migration:risky' => true,
        'array_indentation' => true,
        'array_syntax' => ['syntax' => 'short'],
        'blank_line_after_opening_tag' => true,
        'concat_space' => ['spacing' => 'one'],
        'declare_strict_types' => true,
        'global_namespace_import' => ['import_classes' => true, 'import_constants' => true, 'import_functions' => true],
        'increment_style' => ['style' => 'post'],
        'is_null' => false,
        'list_syntax' => ['syntax' => 'short'],
        'method_argument_space' => ['on_multiline' => "ensure_fully_multiline"],
        'method_chaining_indentation' => true,
        'modernize_types_casting' => true,
        'multiline_whitespace_before_semicolons' => false,
        'no_superfluous_elseif' => true,
        'no_superfluous_phpdoc_tags' => true,
        'no_useless_else' => true,
        'no_useless_return' => true,
        'ordered_imports' => true,
        'phpdoc_align' => false,
        'phpdoc_order' => true,
        'php_unit_construct' => true,
        'php_unit_dedicate_assert' => true,
        'return_assignment' => true,
        'single_line_comment_style' => true,
        'ternary_to_null_coalescing' => true,
        'yoda_style' => ['equal' => false, 'identical' => false, 'less_and_greater' => false],
        'void_return' => true,
    ])
    ->setFinder($finder)
    ->setUsingCache(true)
    ->setRiskyAllowed(true);
