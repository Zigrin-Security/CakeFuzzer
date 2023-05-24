<?php

spl_autoload_register(function ($class) {
    if (0 === strpos($class, 'PhpParser\\')) {
        $filename = getcwd() . '/php-parser/lib/' . str_replace('\\', '/', $class) . '.php';
        if (file_exists($filename)) {
            require_once $filename;
        }
    }
});

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter;

// The first argument (index 1) is the directory
if (!isset($argv[1])) {
    echo "Error: Directory not provided\n";
    exit(1);
}

$directory = $argv[1];

if (!is_dir($directory)) {
    echo "Error: Directory not found\n";
    exit(1);
}


// Construct the iterator
$it = new RecursiveDirectoryIterator($directory);

// Loop through files
foreach(new RecursiveIteratorIterator($it) as $file) {
    if (!is_file($file->getPathname()) || $file->getExtension() !== 'php') {
        print("Skipping {$file->getPathname()}\n");
        continue;
    }

    $inputFile = $file->getPathname();
    print("Processing {$inputFile}\n");
    $preAnnotationFile = $inputFile . '.preannotation';

    // Rename the original file
    rename($inputFile, $preAnnotationFile);

    $content = file_get_contents($preAnnotationFile);

    // Initialize the parser
    $parserFactory = new ParserFactory();
    $parser = $parserFactory->create(ParserFactory::PREFER_PHP7);

    // Parse the input PHP code
    $ast = $parser->parse($content);

    // Create a traverser and add custom node visitors to remove type hints and annotations
    $traverser = new NodeTraverser();
    $traverser->addVisitor(new class extends NodeVisitorAbstract {
        public function leaveNode(\PhpParser\Node $node) {
            if ($node instanceof \PhpParser\Node\Stmt\Function_ || $node instanceof \PhpParser\Node\Stmt\ClassMethod) {
                // Remove return type hints
                $node->returnType = null;

                // Remove argument type hints
                foreach ($node->params as $param) {
                    $param->type = null;
                }
            }

            // if ($node instanceof \PhpParser\Node\Stmt\Class_) {
            //     // Remove PHPDoc comments (annotations)
            //     $node->setDocComment(null);
            // }
        }
    });

    // Traverse and modify the AST
    $modifiedAst = $traverser->traverse($ast);

    // Pretty-print the modified AST to PHP code
    $prettyPrinter = new PrettyPrinter\Standard();
    $modifiedCode = $prettyPrinter->prettyPrintFile($modifiedAst);

    // Save the modified PHP code to the original file
    file_put_contents($inputFile, $modifiedCode);

    echo "Type hints and annotations removed from '{$inputFile}', original file renamed to '{$preAnnotationFile}'\n";
}
