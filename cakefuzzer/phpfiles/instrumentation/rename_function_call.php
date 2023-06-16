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

// The second argument (index 2) is the function name to rename from
if (!isset($argv[2])) {
    echo "Error: Function name to rename from not provided\n";
    exit(1);
}

// The third argument (index 3) is the new function name
if (!isset($argv[3])) {
    echo "Error: New function name not provided\n";
    exit(1);
}

$directory = $argv[1];
$renameFrom = $argv[2];
$renameTo = $argv[3];

if (!is_dir($directory)) {
    echo "Error: Directory not found\n";
    exit(1);
}

// Construct the iterator
$it = new RecursiveDirectoryIterator($directory);

// Loop through files
foreach (new RecursiveIteratorIterator($it) as $file) {
    if (!is_file($file->getPathname()) || $file->getExtension() !== 'php') {
        // print("Skipping {$file->getPathname()}\n");
        continue;
    }

    $inputFile = $file->getPathname();
    $preRenameFile = $inputFile . '.prerename';

    $content = file_get_contents($inputFile);

    try {
        // Initialize the parser
        $parserFactory = new ParserFactory();
        $parser = $parserFactory->create(ParserFactory::PREFER_PHP7);

        // Parse the input PHP code
        $ast = $parser->parse($content);

        $changed = false;

        // Create a traverser and add custom node visitors to rename function calls
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class ($renameFrom, $renameTo) extends NodeVisitorAbstract {
            private $renameFrom;
            private $renameTo;
            private $changed = false;

            public function __construct($renameFrom, $renameTo)
            {
                $this->renameFrom = $renameFrom;
                $this->renameTo = $renameTo;
            }

            public function leaveNode(\PhpParser\Node $node)
            {
                if ($node instanceof \PhpParser\Node\Expr\FuncCall && $node->name instanceof \PhpParser\Node\Name) {
                    // Rename the function call if it matches the provided name
                    if ($node->name->toString() === $this->renameFrom) {
                        $node->name = new \PhpParser\Node\Name($this->renameTo);
                        $this->changed = true;
                        $GLOBALS['changed'] = true;
                    }
                }
            }

            public function hasChanged()
            {
                return $this->changed;
            }
        });

        // Traverse and modify the AST
        $modifiedAst = $traverser->traverse($ast);

        // Pretty-print the modified AST to PHP code
        $prettyPrinter = new PrettyPrinter\Standard();
        $modifiedCode = $prettyPrinter->prettyPrintFile($modifiedAst);

        if ($changed) {
            if (!file_exists($preRenameFile)) {
                // Rename the original file
                rename($inputFile, $preRenameFile);
            }

            // Save the modified PHP code to the original file
            file_put_contents($inputFile, $modifiedCode);

            echo "Function calls to '{$renameFrom}' renamed to '{$renameTo}' in '{$inputFile}', original file renamed to '{$preRenameFile}'\n";
        }
    } catch (Exception $e) {
        echo "Error parsing file '{$inputFile}': " . $e->getMessage() . "\n";
    }
}
