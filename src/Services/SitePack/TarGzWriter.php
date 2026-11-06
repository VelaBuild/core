<?php

namespace VelaBuild\Core\Services\SitePack;

/**
 * A streaming .tar.gz writer: regular files and directories only.
 *
 * Written here rather than shelling out to `tar` because the archive is made on
 * the customer's own machine (macOS, Linux, Windows), where the tar flags
 * differ and may be missing, and PharData builds the whole archive in memory.
 * Long paths use the GNU ././@LongLink entry, which every tar reader handles.
 */
class TarGzWriter
{
    /** @var resource */
    private $out;

    private int $files = 0;

    private int $bytes = 0;

    public function __construct(private string $path)
    {
        $out = @fopen('compress.zlib://' . $path, 'wb');
        if ($out === false) {
            throw new \RuntimeException("cannot write {$path}");
        }
        $this->out = $out;
    }

    public function addFile(string $name, string $source): void
    {
        $size = filesize($source);
        $in = @fopen($source, 'rb');
        if ($size === false || $in === false) {
            throw new \RuntimeException("cannot read {$source}");
        }
        $this->header($name, '0', $size, (int) filemtime($source), 0644);
        $copied = stream_copy_to_stream($in, $this->out);
        fclose($in);
        if ($copied !== $size) {
            throw new \RuntimeException("{$source} changed while it was being packed");
        }
        $this->pad($size);
        $this->files++;
        $this->bytes += $size;
    }

    public function addString(string $name, string $contents): void
    {
        $this->header($name, '0', strlen($contents), time(), 0644);
        fwrite($this->out, $contents);
        $this->pad(strlen($contents));
        $this->files++;
        $this->bytes += strlen($contents);
    }

    public function addDirectory(string $name): void
    {
        $this->header(rtrim($name, '/') . '/', '5', 0, time(), 0755);
    }

    /** @return array{files: int, bytes: int} */
    public function close(): array
    {
        fwrite($this->out, str_repeat("\0", 1024));
        fclose($this->out);

        return ['files' => $this->files, 'bytes' => $this->bytes];
    }

    private function header(string $name, string $type, int $size, int $mtime, int $mode): void
    {
        if (strlen($name) > 100) {
            $long = $name . "\0";
            $this->write('././@LongLink', 'L', strlen($long), 0, 0644);
            fwrite($this->out, $long);
            $this->pad(strlen($long));
            $name = substr($name, 0, 100);
        }
        $this->write($name, $type, $size, $mtime, $mode);
    }

    private function write(string $name, string $type, int $size, int $mtime, int $mode): void
    {
        $octal = fn (int $v, int $len) => str_pad(decoct($v), $len - 1, '0', STR_PAD_LEFT) . "\0";
        $block = str_pad($name, 100, "\0")
            . $octal($mode, 8) . $octal(0, 8) . $octal(0, 8)
            . $octal($size, 12) . $octal($mtime, 12)
            . '        ' . $type . str_repeat("\0", 100)
            . "ustar\0" . '00' . str_repeat("\0", 32 + 32 + 8 + 8 + 155);
        $block = str_pad($block, 512, "\0");
        $sum = array_sum(array_map('ord', str_split($block)));
        $block = substr_replace($block, str_pad(decoct($sum), 6, '0', STR_PAD_LEFT) . "\0 ", 148, 8);
        fwrite($this->out, $block);
    }

    private function pad(int $size): void
    {
        if ($size % 512) {
            fwrite($this->out, str_repeat("\0", 512 - $size % 512));
        }
    }
}
