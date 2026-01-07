<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class StimulusAttrExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('stim_attrs', [$this, 'stimAttrs']),
        ];
    }

    /**
     * @param string $classes
     * @param array<string, mixed> $existingAttr
     * @return array<string, mixed>
     */
    public function stimAttrs(string $classes = '', array $existingAttr = []): array
    {
        $classes = trim($classes);

        $attrs = $existingAttr;
        $controllers = trim((string)($attrs['data-controller'] ?? ''));
        $actions     = trim((string)($attrs['data-action'] ?? ''));

        $classList = preg_split('/\s+/', $classes) ?: [];

        $pushSpace = static function (string $list, string $item): string {
            $list = trim($list);
            $item = trim($item);
            if ($item === '') {
                return $list;
            }
            return $list === '' ? $item : $list.' '.$item;
        };

        foreach ($classList as $c) {
            $c = trim((string)$c);
            if ($c === '') {
                continue;
            }

            // --- Shortcuts tuyos ---
            if ($c === 'js-accordion') {
                $controllers = $pushSpace($controllers, 'admin--accordion-box');
                continue;
            }
            if ($c === 'js-accordion--collapsed') {
                $attrs['data-admin--accordion-box-collapsed-value'] = 'true';
                continue;
            }

            // js-controller-<name>
            if (str_starts_with($c, 'js-controller-')) {
                $controller = substr($c, strlen('js-controller-'));
                $controllers = $pushSpace($controllers, $controller);
                continue;
            }

            // js-action-<action>
            if (str_starts_with($c, 'js-action-')) {
                $action = substr($c, strlen('js-action-'));
                $actions = $pushSpace($actions, $action);
                continue;
            }

            // js-target-<controller>--<target>
            if (str_starts_with($c, 'js-target-')) {
                $raw = substr($c, strlen('js-target-'));
                $parts = explode('--', $raw);
                if (count($parts) >= 2) {
                    $target = array_pop($parts);
                    $controller = implode('--', $parts);
                    if ($controller !== '' && $target !== '') {
                        $key = 'data-'.$controller.'-target';
                        $attrs[$key] = $pushSpace((string)($attrs[$key] ?? ''), $target);
                    }
                }
                continue;
            }

            // js-value-<controller>--<name>--<val>
            if (str_starts_with($c, 'js-value-')) {
                $raw = substr($c, strlen('js-value-'));
                $parts = explode('--', $raw);
                if (count($parts) >= 3) {
                    $value = array_pop($parts);
                    $name  = array_pop($parts);
                    $controller = implode('--', $parts);
                    if ($controller !== '' && $name !== '') {
                        $attrs['data-'.$controller.'-'.$name.'-value'] = $value;
                    }
                }
                continue;
            }

            // js-param-<controller>--<name>--<val>
            if (str_starts_with($c, 'js-param-')) {
                $raw = substr($c, strlen('js-param-'));
                $parts = explode('--', $raw);
                if (count($parts) >= 3) {
                    $value = array_pop($parts);
                    $name  = array_pop($parts);
                    $controller = implode('--', $parts);
                    if ($controller !== '' && $name !== '') {
                        $attrs['data-'.$controller.'-'.$name.'-param'] = $value;
                    }
                }
                continue;
            }
        }

        if (trim($controllers) !== '') {
            $attrs['data-controller'] = trim($controllers);
        }
        if (trim($actions) !== '') {
            $attrs['data-action'] = trim($actions);
        }

        return $attrs;
    }
}