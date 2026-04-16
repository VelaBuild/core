<?php

namespace VelaBuild\Core\Tests\Unit\Services;

use VelaBuild\Core\Services\FigmaExportService;
use VelaBuild\Core\Tests\TestCase;

class FigmaExportServiceTest extends TestCase
{
    protected FigmaExportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FigmaExportService();
    }

    public function test_parse_file_key_extracts_key_from_file_url(): void
    {
        $this->assertEquals('abc123', $this->service->parseFileKey('https://www.figma.com/file/abc123/My-Design'));
    }

    public function test_parse_file_key_extracts_key_from_design_url(): void
    {
        $this->assertEquals('abc123', $this->service->parseFileKey('https://www.figma.com/design/abc123/My-Design'));
    }

    public function test_parse_file_key_returns_null_for_invalid_url(): void
    {
        $this->assertNull($this->service->parseFileKey('https://example.com/not-figma'));
    }
}
