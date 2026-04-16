<?php

namespace VelaBuild\Core;

use VelaBuild\Core\Registries\BlockRegistry;
use VelaBuild\Core\Registries\MenuRegistry;
use VelaBuild\Core\Registries\ProfileMenuRegistry;
use VelaBuild\Core\Registries\TemplateRegistry;
use VelaBuild\Core\Registries\ToolRegistry;
use VelaBuild\Core\Registries\WidgetRegistry;

class Vela
{
    public function __construct(
        protected BlockRegistry $blockRegistry,
        protected MenuRegistry $menuRegistry,
        protected TemplateRegistry $templateRegistry,
        protected WidgetRegistry $widgetRegistry,
        protected ToolRegistry $toolRegistry = new ToolRegistry(),
        protected ProfileMenuRegistry $profileMenuRegistry = new ProfileMenuRegistry(),
    ) {}

    public function blocks(): BlockRegistry
    {
        return $this->blockRegistry;
    }

    public function menus(): MenuRegistry
    {
        return $this->menuRegistry;
    }

    public function templates(): TemplateRegistry
    {
        return $this->templateRegistry;
    }

    public function widgets(): WidgetRegistry
    {
        return $this->widgetRegistry;
    }

    public function registerBlock(string $name, array $config): void
    {
        $this->blockRegistry->register($name, $config);
    }

    public function registerMenuItem(string $name, array $config): void
    {
        $this->menuRegistry->register($name, $config);
    }

    public function registerTemplate(string $name, array $config): void
    {
        $this->templateRegistry->register($name, $config);
    }

    public function registerWidget(string $name, array $config): void
    {
        $this->widgetRegistry->register($name, $config);
    }

    public function tools(): ToolRegistry
    {
        return $this->toolRegistry;
    }

    public function registerTool(string $name, array $config): void
    {
        $this->toolRegistry->register($name, $config);
    }

    public function profileMenu(): ProfileMenuRegistry
    {
        return $this->profileMenuRegistry;
    }

    public function registerProfileMenuItem(string $name, array $config): void
    {
        $this->profileMenuRegistry->register($name, $config);
    }
}
