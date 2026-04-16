<div id="page-block-editor">
    <div id="rows-container">
        {{-- Rows rendered by JS --}}
    </div>
    <button type="button" id="add-row-btn" class="btn btn-success btn-block mt-3">
        <i class="fas fa-plus"></i> {{ trans('vela::global.add_row') }}
    </button>
</div>

{{-- Block Edit Modal --}}
<div class="modal fade" id="block-edit-modal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">{{ trans('vela::global.edit_block') }}</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body" id="block-edit-content">
                {{-- Dynamic content loaded by JS based on block type --}}
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ trans('vela::global.cancel') }}</button>
                <button type="button" class="btn btn-primary" id="save-block-btn">{{ trans('vela::global.save_block') }}</button>
            </div>
        </div>
    </div>
</div>

{{-- Column Layout Modal --}}
<div class="modal fade" id="column-layout-modal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">{{ trans('vela::global.select_column_layout') }}</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-12 mb-2">
                        <button type="button" class="btn btn-outline-secondary btn-block layout-btn" data-widths="[12]">
                            <div class="layout-preview"><div style="flex:12" class="lp-col">100%</div></div>
                            {{ trans('vela::global.one_column') }}
                        </button>
                    </div>
                    <div class="col-12 mb-2">
                        <button type="button" class="btn btn-outline-secondary btn-block layout-btn" data-widths="[6,6]">
                            <div class="layout-preview"><div style="flex:6" class="lp-col">50%</div><div style="flex:6" class="lp-col">50%</div></div>
                            {{ trans('vela::global.two_columns_equal') }}
                        </button>
                    </div>
                    <div class="col-12 mb-2">
                        <button type="button" class="btn btn-outline-secondary btn-block layout-btn" data-widths="[4,8]">
                            <div class="layout-preview"><div style="flex:4" class="lp-col">33%</div><div style="flex:8" class="lp-col">67%</div></div>
                            {{ trans('vela::global.two_columns_33_67') }}
                        </button>
                    </div>
                    <div class="col-12 mb-2">
                        <button type="button" class="btn btn-outline-secondary btn-block layout-btn" data-widths="[8,4]">
                            <div class="layout-preview"><div style="flex:8" class="lp-col">67%</div><div style="flex:4" class="lp-col">33%</div></div>
                            {{ trans('vela::global.two_columns_67_33') }}
                        </button>
                    </div>
                    <div class="col-12 mb-2">
                        <button type="button" class="btn btn-outline-secondary btn-block layout-btn" data-widths="[4,4,4]">
                            <div class="layout-preview"><div style="flex:4" class="lp-col">33%</div><div style="flex:4" class="lp-col">33%</div><div style="flex:4" class="lp-col">33%</div></div>
                            {{ trans('vela::global.three_columns') }}
                        </button>
                    </div>
                    <div class="col-12 mb-2">
                        <button type="button" class="btn btn-outline-secondary btn-block layout-btn" data-widths="[3,3,3,3]">
                            <div class="layout-preview"><div style="flex:3" class="lp-col">25%</div><div style="flex:3" class="lp-col">25%</div><div style="flex:3" class="lp-col">25%</div><div style="flex:3" class="lp-col">25%</div></div>
                            {{ trans('vela::global.four_columns') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.page-row-editor { border: 1px solid #dee2e6; border-radius: 4px; margin-bottom: 16px; }
.page-row-editor .card-header { background: #f8f9fa; padding: 8px 12px; }
.page-column-editor { border: 1px dashed #ced4da; border-radius: 4px; padding: 8px; min-height: 60px; background: #fff; }
.page-block-editor-item { border: 1px solid #dee2e6; border-radius: 4px; margin-bottom: 6px; background: #fff; }
.page-block-editor-item .block-header { padding: 6px 10px; display: flex; justify-content: space-between; align-items: center; background: #f8f9fa; border-radius: 4px 4px 0 0; }
.page-block-editor-item .block-preview-text { padding: 10px; font-size: 0.85em; color: #333; }
.page-block-editor-item .block-preview-text p { margin: 0 0 0.5em; }
.page-block-editor-item .block-preview-text h2, .page-block-editor-item .block-preview-text h3, .page-block-editor-item .block-preview-text h4 { margin: 0.3em 0; }
.page-block-editor-item .block-preview-text ul, .page-block-editor-item .block-preview-text ol { padding-left: 1.5em; margin: 0 0 0.5em; }
.page-block-editor-item .block-preview-text img { max-width: 100%; border-radius: 4px; }
.drag-handle { cursor: grab; color: #aaa; }
.layout-preview { display: flex; gap: 4px; height: 30px; margin-bottom: 6px; }
.lp-col { background: #dee2e6; border-radius: 3px; display: flex; align-items: center; justify-content: center; font-size: 12px; color: #495057; }
.column-header-label { font-size: 0.75em; color: #6c757d; text-align: center; padding: 2px; background: #f0f0f0; border-radius: 3px; margin-bottom: 4px; }
#accordion-items-list .accordion-item-row { display: flex; gap: 8px; align-items: flex-start; margin-bottom: 8px; padding: 8px; background: #f8f9fa; border-radius: 4px; }
.block-img-dz { border: 2px dashed #ced4da; border-radius: 4px; padding: 20px; text-align: center; cursor: pointer; min-height: 80px; background: #fafafa; transition: border-color 0.2s; }
.block-img-dz:hover { border-color: #80bdff; }
.block-img-dz .dz-message { margin: 0; color: #6c757d; }
</style>

@push('scripts')
<script>
window.PageEditorConfig = {
    uploadUrl: '{{ route("vela.admin.pages.storeCKEditorImages") }}',
    categories: @json(\VelaBuild\Core\Models\Category::orderBy('order_by')->orderBy('name')->get(['id', 'name']))
};
</script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@editorjs/editorjs@2"></script>
<script src="https://cdn.jsdelivr.net/npm/@editorjs/header@2"></script>
<script src="https://cdn.jsdelivr.net/npm/@editorjs/list@1"></script>
<script src="https://cdn.jsdelivr.net/npm/@editorjs/quote@2"></script>
<script src="https://cdn.jsdelivr.net/npm/@editorjs/table@2"></script>
<script src="https://cdn.jsdelivr.net/npm/@editorjs/embed@2"></script>
<script src="https://cdn.jsdelivr.net/npm/@editorjs/image@2"></script>
<script src="{{ asset('vendor/vela/js/page-editor.js') }}"></script>
@endpush
