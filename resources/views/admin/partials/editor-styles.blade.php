<style>
.content-editor-page .section-card {
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 1px 3px rgba(0,0,0,.04);
    margin-bottom: 1.25rem;
    overflow: hidden;
}
.content-editor-page .section-card .section-header {
    padding: 14px 20px;
    background: #f9fafb;
    border-bottom: 1px solid #e5e7eb;
    font-weight: 600;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: #6b7280;
    display: flex;
    align-items: center;
    gap: 8px;
}
.content-editor-page .section-card .section-body { padding: 20px; }
.content-editor-page .form-group { margin-bottom: 1.25rem; }
.content-editor-page .form-group:last-child { margin-bottom: 0; }
.content-editor-page .form-group label {
    font-weight: 500;
    font-size: 0.875rem;
    color: #374151;
    margin-bottom: 0.4rem;
}
.content-editor-page .help-block {
    font-size: 0.78rem;
    color: #9ca3af;
    margin-top: 0.25rem;
    display: block;
}
.content-editor-page .title-input {
    font-size: 1.5rem;
    font-weight: 600;
    border: none;
    border-bottom: 2px solid #e5e7eb;
    border-radius: 0;
    padding: 0.5rem 0;
    transition: border-color .2s;
}
.content-editor-page .title-input:focus {
    box-shadow: none;
    border-color: #4f46e5;
}
.content-editor-page .publish-btn {
    background: #4f46e5;
    border: none;
    color: #fff;
    font-weight: 600;
    padding: 10px 0;
    border-radius: 8px;
    font-size: 0.95rem;
    transition: background .2s;
}
.content-editor-page .publish-btn:hover { background: #4338ca; color: #fff; }
.content-editor-page #editorjs {
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    min-height: 300px;
    padding: 16px;
    background: #fff;
}
.content-editor-page .editorjs-trans {
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    min-height: 200px;
    padding: 12px;
    background: #fff;
}
.content-editor-page .lang-tabs { border-bottom: 2px solid #e5e7eb; }
.content-editor-page .lang-tabs .nav-link {
    border: none;
    color: #6b7280;
    font-weight: 500;
    font-size: 0.85rem;
    padding: 8px 16px;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    transition: all .2s;
    display: flex;
    align-items: center;
    gap: 6px;
}
.content-editor-page .lang-tabs .nav-link.active {
    color: #4f46e5;
    border-bottom-color: #4f46e5;
    background: transparent;
}
.content-editor-page .lang-tabs .flag-icon {
    width: 18px;
    height: 13px;
    border-radius: 2px;
    object-fit: cover;
}
.content-editor-page .nav-tabs { border-bottom: 2px solid #e5e7eb; }
.content-editor-page .nav-tabs .nav-link {
    border: none;
    color: #6b7280;
    font-weight: 500;
    font-size: 0.85rem;
    padding: 8px 16px;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    transition: all .2s;
}
.content-editor-page .nav-tabs .nav-link.active {
    color: #4f46e5;
    border-bottom-color: #4f46e5;
    background: transparent;
}
.content-editor-page .dropzone {
    border: 2px dashed #d1d5db;
    border-radius: 8px;
    background: #f9fafb;
    min-height: 120px;
    transition: border-color .2s;
}
.content-editor-page .dropzone:hover { border-color: #4f46e5; }
.content-editor-page .image-thumb {
    width: 100%;
    height: 80px;
    object-fit: cover;
    border-radius: 6px;
    border: 1px solid #e5e7eb;
}
.content-editor-page .image-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(90px, 1fr));
    gap: 8px;
}
.content-editor-page .image-grid-item { position: relative; }
.content-editor-page .image-grid-item .remove-btn {
    position: absolute; top: 4px; right: 4px;
    width: 22px; height: 22px; border-radius: 50%;
    background: rgba(239,68,68,.9); color: #fff; border: none;
    font-size: 11px; display: flex; align-items: center; justify-content: center;
    cursor: pointer; opacity: 0; transition: opacity .2s;
}
.content-editor-page .image-grid-item:hover .remove-btn { opacity: 1; }
</style>
