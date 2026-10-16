<?php

declare(strict_types=1);

namespace Tests\Unit;

use Padosoft\AskMyDocsConnectorMicrosoftMail\Mail\AttachmentPreparer;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class AttachmentPreparationTest extends TestCase
{
    /** @return array<string, mixed> */
    private function file(string $id, string $name = 'report.txt', string $bytes = 'complete file content'): array
    {
        return ['id' => $id, 'name' => $name, 'contentType' => 'text/plain', '@odata.type' => '#microsoft.graph.fileAttachment', 'contentBytes' => base64_encode($bytes)];
    }

    public function test_manifest_is_case_sensitive_sorted_and_omonynous_files_have_distinct_slots(): void
    {
        $attachments = [$this->file('a'), $this->file('A'), $this->file('b', '../../report.txt')];
        $prepared = (new AttachmentPreparer)->prepare($attachments);
        self::assertSame(['A', 'a', 'b'], array_column($prepared['manifest'], 'id'));
        self::assertSame([0, 1, 2], array_column($prepared['manifest'], 'slot'));
        self::assertSame(['report.txt', 'report.txt', 'report.txt'], array_column($prepared['manifest'], 'name'));
        self::assertSame($prepared, (new AttachmentPreparer)->prepare(array_reverse($attachments)));
        self::assertSame(hash('sha256', 'complete file content'), $prepared['manifest'][0]['hash']);
    }

    public function test_every_excluded_type_and_policy_is_visible_in_manifest(): void
    {
        $attachments = [
            array_replace($this->file('inline'), ['isInline' => true]),
            ['id' => 'reference', 'name' => 'remote.txt', '@odata.type' => '#microsoft.graph.referenceAttachment', 'sourceUrl' => 'https://remote.invalid/secret'],
            $this->file('empty', bytes: ''),
            $this->file('extension', 'binary.exe'),
            array_replace($this->file('mismatch', 'fake.pdf'), ['contentType' => 'application/pdf']),
        ];
        $prepared = (new AttachmentPreparer)->prepare($attachments);
        self::assertSame([], $prepared['files']);
        self::assertSame(['empty_attachment', 'extension_not_allowed', 'inline_attachment', 'mime_mismatch', 'reference_attachment_not_downloaded'], array_column($prepared['manifest'], 'reason'));
        self::assertSame([null, null, null, null, null], array_column($prepared['manifest'], 'hash'));
    }

    public function test_size_count_and_total_budgets_are_checked_against_acquired_bytes(): void
    {
        $attachments = [$this->file('A', bytes: 'hello'), $this->file('B', bytes: 'world'), $this->file('C', bytes: 'third')];
        $prepared = (new AttachmentPreparer)->prepare($attachments, ['max_bytes' => 4]);
        self::assertSame(['attachment_size_limit', 'attachment_size_limit', 'attachment_size_limit'], array_column($prepared['manifest'], 'reason'));
        $prepared = (new AttachmentPreparer)->prepare($attachments, ['max_total_bytes' => 8]);
        self::assertCount(1, $prepared['files']);
        self::assertSame('message_attachment_byte_limit', $prepared['manifest'][1]['reason']);
        $prepared = (new AttachmentPreparer)->prepare($attachments, ['max_count' => 1]);
        self::assertSame('attachment_count_limit', $prepared['manifest'][1]['reason']);
        $prepared = (new AttachmentPreparer)->prepare($attachments, ['enabled' => false]);
        self::assertSame('attachments_disabled', $prepared['manifest'][0]['reason']);
    }

    public function test_nested_message_items_are_rendered_with_nested_file_coverage_and_depth_budget(): void
    {
        $attachment = [
            'id' => 'item', 'name' => 'forwarded.eml', '@odata.type' => '#microsoft.graph.itemAttachment',
            'item' => ['@odata.type' => '#microsoft.graph.message', 'subject' => 'Nested email', 'body' => ['contentType' => 'text', 'content' => 'Nested full body'], 'attachments' => [$this->file('child')]],
        ];
        $prepared = (new AttachmentPreparer)->prepare([$attachment]);
        self::assertCount(2, $prepared['manifest']);
        self::assertSame('text/markdown', $prepared['files'][0]['mime']);
        self::assertSame('forwarded.md', $prepared['files'][0]['name']);
        self::assertStringContainsString('Nested full body', $prepared['files'][0]['bytes']);
        self::assertSame('item/child', $prepared['files'][1]['id']);
        $limited = (new AttachmentPreparer)->prepare([$attachment], ['max_depth' => 0]);
        self::assertSame('attachment_depth_limit', $limited['manifest'][1]['reason']);
    }

    public function test_all_observed_nested_descendants_remain_visible_beyond_depth_and_coverage_is_bounded(): void
    {
        $nested = ['id' => 'child-item', 'name' => 'child.eml', '@odata.type' => '#microsoft.graph.itemAttachment', 'item' => ['body' => ['contentType' => 'text', 'content' => 'child body'], 'attachments' => [$this->file('grandchild')]]];
        $outer = ['id' => 'outer', 'name' => 'outer.eml', '@odata.type' => '#microsoft.graph.itemAttachment', 'item' => ['body' => ['contentType' => 'text', 'content' => 'outer body'], 'attachments' => [$nested]]];
        $prepared = (new AttachmentPreparer)->prepare([$outer], ['max_depth' => 0]);
        self::assertCount(3, $prepared['manifest']);
        self::assertSame('attachment_depth_limit', $prepared['manifest'][1]['reason']);
        self::assertSame('attachment_depth_limit', $prepared['manifest'][2]['reason']);
        $this->expectException(UnexpectedValueException::class);
        (new AttachmentPreparer)->prepare([$outer], ['max_observed' => 2]);
    }

    public function test_duplicate_pages_do_not_duplicate_manifest_and_conflicting_identity_fails(): void
    {
        $file = $this->file('same');
        self::assertCount(1, (new AttachmentPreparer)->prepare([$file, $file])['manifest']);
        $this->expectException(UnexpectedValueException::class);
        (new AttachmentPreparer)->prepare([$file, $this->file('same', bytes: 'different')]);
    }

    public function test_missing_or_invalid_acquired_file_content_is_a_real_error(): void
    {
        $this->expectException(UnexpectedValueException::class);
        (new AttachmentPreparer)->prepare([array_replace($this->file('bad'), ['contentBytes' => '!not base64!'])]);
    }

    public function test_transport_exclusions_and_nested_redaction_precede_manifest_hash(): void
    {
        $item = ['id' => 'item', 'name' => 'nested.eml', '@odata.type' => '#microsoft.graph.itemAttachment', 'item' => ['body' => ['contentType' => 'text', 'content' => 'PRIVATE CONTENT']]];
        $prepared = (new AttachmentPreparer(redactor: static fn (string $bytes): string => str_replace('PRIVATE', 'REDACTED', $bytes)))->prepare([$item]);
        self::assertStringContainsString('REDACTED CONTENT', $prepared['files'][0]['bytes']);
        self::assertSame(hash('sha256', $prepared['files'][0]['bytes']), $prepared['manifest'][0]['hash']);
        $metadataOnly = ['id' => 'file', 'name' => 'file.txt', '__excluded_reason' => 'transport_byte_limit'];
        $excluded = (new AttachmentPreparer)->prepare([$metadataOnly]);
        self::assertSame('transport_byte_limit', $excluded['manifest'][0]['reason']);
        self::assertSame([], $excluded['files']);
    }

    public function test_missing_native_id_uses_deterministic_part_path_and_reserved_filename_is_safe(): void
    {
        $file = array_replace($this->file(''), ['part_path' => 'multipart/2', 'name' => '..\\..\\NUL.txt']);
        $prepared = (new AttachmentPreparer)->prepare([$file]);
        self::assertSame('synthetic:'.hash('sha256', 'multipart/2'), $prepared['manifest'][0]['id']);
        self::assertSame('file-NUL.txt', $prepared['manifest'][0]['name']);
    }
}
