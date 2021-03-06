<?php declare(strict_types=1);

namespace Documentor\src\Application\Controllers;

use Documentor\src\Application\Models\Comment;
use Documentor\src\Application\Views\ClassView;
use Documentor\src\Application\Views\DocView;
use Documentor\src\Application\Views\MethodView;
use Documentor\src\Application\Views\TableOfContentsView;

class DocumentationController
{
    private string $destination = '';
    private string $base        = '';
    private string $sourcePath  = '';

    private ?CodeCoverageController $codeCoverage = null;
    private ?UnitTestController $unitTest          = null;

    private array $files          = [];
    private array $loc            = [];
    private array $stats          = ['loc' => 0, 'classes' => 0, 'traits' => 0, 'interfaces' => 0, 'abstracts' => 0, 'methods' => 0];
    private array $withoutComment = [];

    public function __construct(string $destination, string $base, string $source, CodeCoverageController $codeCoverage, UnitTestController $unitTest)
    {
        $this->destination  = $destination;
        $this->base         = $base;
        $this->codeCoverage = $codeCoverage;
        $this->unitTest     = $unitTest;
        $this->sourcePath   = $source;

        $this->createBaseFiles();
    }

    public function parse(\SplFileInfo $file): void
    {
        $classView = $this->parseClass($file->getPathname());

        if ($classView->getPath() !== '') {
            \mkdir(\dirname($classView->getPath()), 0777, true);
            \file_put_contents($classView->getPath(), $classView->render());
        }
    }

    public function createSearchSet(): void
    {
        $js = 'var searchDataset = [];';
        foreach ($this->files as $file) {
            $js .= "\n" . 'searchDataset.push([\'' . \str_replace('\\', '\\\\', $file[0]) . '\', \'' . $file[1] . '\']);';
        }

        \mkdir($this->destination, 0777, true);
        \file_put_contents($this->destination . '/js/searchDataset.js', $js);
    }

    public function createTableOfContents(): void
    {
        $tocView = new TableOfContentsView();
        $tocView->setPath($this->destination . '/documentation' . '.html');
        $tocView->setBase($this->base);
        $tocView->setTemplate('/Documentor/src/Theme/documentation');
        $tocView->setTitle('Table of Contents');
        $tocView->setSection('Documentation');
        $tocView->setStats($this->stats);
        $tocView->setWithoutComment($this->withoutComment);

        \mkdir(\dirname($tocView->getPath()), 0777, true);
        \file_put_contents($tocView->getPath(), $tocView->render());
    }

    private function parseClass(string $path) : DocView
    {
        $classView = new ClassView();
        $path      = \str_replace('\\', '/', $path);

        try {
            include_once $path;

            $this->loc           = \file($path);
            $this->stats['loc'] += \count($this->loc);

            $className = \substr($path, \strlen(\rtrim(\dirname($this->sourcePath), '/\\')), -4);
            $className = \str_replace('/', '\\', $className);
            $class     = new \ReflectionClass($className);

            $this->files[] = [$class->getName(), $class->getShortName()];
            $outPath       = $this->destination . '/' . \str_replace('\\', '/', $class->getName());

            $classView->setPath($outPath . '.html');
            $classView->setBase($this->base);
            $classView->setTemplate('/Documentor/src/Theme/class');
            $classView->setTitle($class->getShortName());
            $classView->setSection('Documentation');

            if ($class->isInterface()) {
                ++$this->stats['interfaces'];
            } elseif ($class->isTrait()) {
                ++$this->stats['traits'];
            } elseif ($class->isAbstract()) {
                ++$this->stats['abstracts'];
            } elseif ($class->isUserDefined()) {
                ++$this->stats['classes'];
            }

            $classView->setReflection($class);
            $classView->setComment(new Comment($class->getDocComment()));
            $classView->setCoverage($this->codeCoverage->getClass($class->getName()) ?? []);

            // Parse uses
            if ($class->getParentClass() !== false) {
                $classView->addUse($class->inNamespace());
            }

            $interfaces = $class->getInterfaces();
            foreach ($interfaces as $interface) {
                $classView->addUse($interface->inNamespace());
            }

            foreach ($this->loc as $line) {
                $line = \trim($line);

                if (\substr($line, 0, 4) === 'use ') {
                    $classView->addUse(\substr($line, 4, -1));
                }
            }

            $methods = $class->getMethods();
            foreach ($methods as $method) {
                if ($method->isUserDefined()) {
                    $this->parseMethod($method, $outPath . '-' . $method->getShortName() . '.html', $class->getName());
                    $this->files[] = [$class->getName() . '-' . $method->getShortName(), $class->getShortName() . '-' . $method->getShortName()];
                }
            }

        } catch (\Exception $e) {
            echo $e->getMessage(), ' - ', $e->getFile(), ' - ', $e->getLine(), "\n";
        } finally {
            return $classView;
        }
    }

    private function parseMethod(\ReflectionMethod $method, string $destination, string $className): void
    {
        $methodView = new MethodView();
        $methodView->setTemplate('/Documentor/src/Theme/method');
        $methodView->setBase($this->base);
        $methodView->setReflection($method);
        $docs = $method->getDocComment();

        try {
            if (\strpos($docs, '@inheritdoc') !== false) {
                $comment = new Comment($method->getPrototype()->getDocComment());
            } else {
                $comment = new Comment($docs);
            }
        } catch (\Exception $e) {
            $comment = new Comment($docs);
        }

        $methodView->setComment($comment);
        $methodView->setPath($destination);
        $methodView->setCoverage($this->codeCoverage->getMethod($className, $method->getShortName()) ?? []);
        $methodView->setTitle($method->getDeclaringClass()->getShortName() . ' ~ ' . $method->getShortName());
        $methodView->setSection('Documentation');
        $methodView->setCode(\implode('', \array_slice($this->loc, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1)));
        ++$this->stats['methods'];

        if ($comment->isEmpty()) {
            $this->withoutComment[] = $className . '-' . $method->getShortName();
        }

        \mkdir(\dirname($methodView->getPath()), 0777, true);
        \file_put_contents($methodView->getPath(), $methodView->render());
    }

    private function createBaseFiles(): void
    {
        try {
            \mkdir($this->destination . '/css/', 0777, true);
            \mkdir($this->destination . '/js/', 0777, true);
            \mkdir($this->destination . '/img/', 0777, true);

            \copy(__DIR__ . '/../../Theme/css/styles.css', $this->destination . '/css/styles.css');
            \copy(__DIR__ . '/../../Theme/js/documentor.js', $this->destination . '/js/documentor.js');

            $images = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../Theme/img'));
            foreach ($images as $image) {
                if ($image->isFile()) {
                    \copy($image->getPathname(), $this->destination . '/img/' . $image->getFilename());
                }
            }
        } catch (\Exception $e) {
            echo $e->getMessage();
        }
    }
}
