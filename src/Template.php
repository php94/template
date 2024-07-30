<?php

declare(strict_types=1);

namespace PHP94\Template;

use Psr\SimpleCache\CacheInterface;
use SplPriorityQueue;
use Throwable;

class Template
{
    protected $cache = null;

    protected $extends = [];
    protected $finder;

    protected $data = [];
    protected $filename = '';

    private $literals = [];

    public function __construct()
    {
        $this->finder = new SplPriorityQueue;

        if (!in_array('tpls', stream_get_wrappers())) {
            stream_wrapper_register('tpls', Stream::class);
        }

        $this->addFinder(function (string $tpl): ?string {
            if (is_file($tpl)) {
                return file_get_contents($tpl);
            }
            return null;
        }, -100);
    }

    public function setCache(CacheInterface $cache): self
    {
        $this->cache = $cache;
        return $this;
    }

    private function getContent(string $tpl): string
    {
        foreach (clone $this->finder as $finder) {
            $res = $finder($tpl);
            if (!is_null($res)) {
                return $res;
            }
        }
        throw new TplNotFoundException('template [' . $tpl . '] is not found!');
    }

    public function addFinder(callable $callable, $priority = 0): self
    {
        $this->finder->insert($callable, $priority);
        return $this;
    }

    public function extend(string $preg, callable $callback): self
    {
        $this->extends[$preg] = $callback;
        return $this;
    }

    public function assign($name, $value = null): self
    {
        if (is_array($name)) {
            $this->data = array_merge($this->data, $name);
        } else {
            $this->data[$name] = $value;
        }
        return $this;
    }

    public function render(string $tpl, array $data = []): string
    {
        return $this->renderString($this->getContent($tpl), $data, $tpl);
    }

    public function renderString(string $string, array $data = [], string $filename = null): string
    {
        if ($data) {
            $this->assign($data);
        }

        if (is_string($filename)) {
            $cache_key = str_replace(['{', '}', '(', ')', '/', '\\', '@', ':'], '_', 'tpl_' . $filename);
        } else {
            $cache_key = 'tpl_' . md5($string);
        }

        if ($this->cache && $this->cache->has($cache_key)) {
            $code = $this->cache->get($cache_key);
        } else {
            $code = $this->parse($string);

            $code = preg_replace_callback('/\?>[\s]*<\?php/Ui', function ($matchs) {
                return '';
            }, $code);

            $code = str_replace(
                array_keys($this->literals),
                array_values($this->literals),
                $code
            );

            if ($this->cache) {
                $this->cache->set($cache_key, $code);
            }
        }

        $this->filename = 'tpls://' . (is_string($filename) ? $filename : md5($string));
        file_put_contents($this->filename, $code);

        return (string) (function () {
            try {
                ob_start();
                extract($this->data);
                include $this->filename;
                return ob_get_clean();
            } catch (Throwable $th) {
                @ob_get_clean();
                throw new RenderException($th->getMessage(), $th->getCode(), $th);
            }
        })();
    }

    private function buildLiteral(string $string): string
    {
        $key = '#literal_' . md5('<literal>' . $string . '</literal>') . '#';
        $this->literals[$key] = $string;
        return $key;
    }

    private function parseLiteral(string $string): string
    {
        return preg_replace_callback('/{literal}([\s\S]*){\/literal}/Ui', function ($matchs) {
            return $this->buildLiteral($matchs[1]);
        }, $string);
    }

    public function parse(string $string): string
    {
        $string = $this->parseLiteral($string);

        $string = preg_replace_callback('/\{include\s*([\w\-_\.,@\/]*)\}/Ui', function ($matchs) {
            $str = '';
            foreach (explode(',', $matchs[1]) as $tpl) {
                $str .= $this->getContent($tpl);
            }
            return $this->parse($str);
        }, $string);

        $tags = [
            '/\{(foreach|if|for|switch|while)\s+(.*)\}/Ui' => function ($matchs) {
                return '<?php ' . $matchs[1] . ' (' . $matchs[2] . ') { ?>';
            },
            '/\{function\s+(.*)\}/Ui' => function ($matchs) {
                return '<?php function ' . $matchs[1] . '{ ?>';
            },
            '/\{php\s+(.*)\s*;?\s*\}/Ui' => function ($matchs) {
                return '<?php ' . $matchs[1] . '; ?>';
            },
            '/\{dump\s+(.*)\s*;?\s*\}/Ui' => function ($matchs) {
                return '<pre><?php ob_start();var_dump(' . $matchs[1] . ');echo htmlspecialchars((string)ob_get_clean()); ?></pre>';
            },
            '/\{print\s+(.*)\s*;?\s*\}/Ui' => function ($matchs) {
                return '<pre><?php echo htmlspecialchars((string)print_r(' . $matchs[1] . ', true)); ?></pre>';
            },
            '/\{echo\s+(.*)\s*;?\s*\}/Ui' => function ($matchs) {
                return '<?php echo ' . $matchs[1] . '; ?>';
            },
            '/\{case\s+(.*)\}/Ui' => function ($matchs) {
                return '<?php case ' . $matchs[1] . ': ?>';
            },
            '/\{default\s*\}/Ui' => function () {
                return '<?php default: ?>';
            },
            '/\{php\}/Ui' => function () {
                return '<?php ';
            },
            '/\{\/php\}/Ui' => function () {
                return ' ?>';
            },
            '/\{\/(foreach|if|for|function|switch|while)\}/Ui' => function () {
                return '<?php } ?>';
            },
            '/\{\/(case|default)\}/Ui' => function () {
                return '<?php break; ?>';
            },
            '/\{(elseif)\s+(.*)\}/Ui' => function ($matchs) {
                return '<?php }' . $matchs[1] . '(' . $matchs[2] . '){ ?>';
            },
            '/\{else\/?\}/Ui' => function () {
                return '<?php }else{ ?>';
            },
            '/\{css\s*([\w\-_\.,@\/]*)\}/Ui' => function ($matchs) {
                $str = '';
                foreach (explode(',', $matchs[1]) as $tpl) {
                    $str .= '<style>' . $this->getContent($tpl) . '</style>';
                }
                return $this->buildLiteral($str);
            },
            '/\{js\s*([\w\-_\.,@\/]*)\}/Ui' => function ($matchs) {
                $str = '';
                foreach (explode(',', $matchs[1]) as $tpl) {
                    $str .= '<script>' . $this->getContent($tpl) . '</script>';
                }
                return $this->buildLiteral($str);
            },
            '/\{(\$[^{}\'"]*)((\.[^{}\'"]+)+)\}/Ui' => function ($matchs) {
                $p = $matchs[1] . substr(str_replace('.', '\'][\'', $matchs[2]), 2) . '\']';
                return '<?php echo htmlspecialchars((string)(' . $p . ')); ?>';
            },
            '/\{(\$[^{}]*)\}/Ui' => function ($matchs) {
                return '<?php echo htmlspecialchars((string)(' . $matchs[1] . ')); ?>';
            },
            '/\{:([^{}]*)\s*;?\s*\}/Ui' => function ($matchs) {
                return '<?php echo htmlspecialchars((string)(' . $matchs[1] . ')); ?>';
            },
        ];
        $tags = array_merge($tags, $this->extends);
        foreach ($tags as $preg => $callback) {
            $string = preg_replace_callback($preg, function ($matchs) use ($callback) {
                return $callback($matchs);
            }, $string);
        }
        return $string;
    }
}
