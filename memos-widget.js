// WordPress Memos Widget JavaScript

class MemosWidget {
    constructor(container, apiUrl, pageSize = 5, contentLength = 65, accessToken = '') {
        this.container = container;
        this.apiUrl = (apiUrl || '').replace(/\/+$/, '');
        this.pageSize = pageSize;
        this.contentLength = contentLength;
        this.accessToken = accessToken;
        this.init();
    }

    async init() {
        try {
            const memos = await this.fetchMemos();
            this.renderMemos(memos);
        } catch (error) {
            console.error('Failed to initialize Memos widget:', error);
            this.renderError(error);
        }
    }

    async fetchMemos() {
        const headers = {};
        if (this.accessToken) {
            headers['Authorization'] = `Bearer ${this.accessToken}`;
        }

        const response = await fetch(`${this.apiUrl}/api/v1/memos?pageSize=${this.pageSize}`, {
            headers: headers
        });

        if (!response.ok) {
            let errorMsg = `HTTP error! status: ${response.status}`;
            if (response.status === 401) {
                errorMsg = '401 Unauthorized';
            }
            throw new Error(errorMsg);
        }
        return await response.json();
    }

    renderMemos(memos) {
        let memosArray = [];
        
        if (Array.isArray(memos)) {
            memosArray = memos;
        } else if (memos && typeof memos === 'object') {
            if (Array.isArray(memos.memos)) {
                memosArray = memos.memos;
            } else if (Array.isArray(memos.data)) {
                memosArray = memos.data;
            } else if (Array.isArray(memos.list)) {
                memosArray = memos.list;
            } else if (Array.isArray(memos.rows)) {
                memosArray = memos.rows;
            }
        }
        
        if (!memosArray || memosArray.length === 0) {
            this.container.innerHTML = '<p class="memos-empty">暂无动态</p>';
            return;
        }

        const wrapper = document.createElement('div');
        wrapper.className = 'memos-widget-wrapper';

        const ul = document.createElement('ul');
        ul.className = 'memos-list';

        memosArray.forEach(memo => {
            const li = document.createElement('li');
            li.className = 'memos-item';
            
            const content = document.createElement('div');
            content.className = 'memos-content';
            const rawContent = memo.content || '';
            const truncatedContent = rawContent.length > this.contentLength 
                ? rawContent.substring(0, this.contentLength) + '...' 
                : rawContent;
            content.textContent = truncatedContent;
            li.appendChild(content);

            if (Array.isArray(memo.tags) && memo.tags.length > 0) {
                const tagsDiv = document.createElement('div');
                tagsDiv.className = 'memos-tags';
                memo.tags.forEach(tag => {
                    const tagSpan = document.createElement('span');
                    tagSpan.className = 'memos-tag';
                    tagSpan.textContent = '#' + tag.replace(/^#/, '');
                    tagsDiv.appendChild(tagSpan);
                });
                li.appendChild(tagsDiv);
            }

            const footer = document.createElement('div');
            footer.className = 'memos-footer';

            const time = document.createElement('span');
            time.className = 'memos-time';
            const rawTime = memo.createTime || memo.displayTime || memo.createdTs;
            let date;
            if (typeof rawTime === 'number') {
                date = new Date(rawTime * 1000);
            } else if (rawTime) {
                date = new Date(rawTime);
            } else {
                date = new Date();
            }
            const dateStr = isNaN(date.getTime()) ? '' : date.toLocaleDateString();
            time.innerHTML = `<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:2px"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>${dateStr}`;

            const more = document.createElement('a');
            more.className = 'memos-more';
            let memoId = '';
            if (memo.uid) {
                memoId = memo.uid;
            } else if (memo.name && typeof memo.name === 'string' && memo.name.includes('/')) {
                memoId = memo.name.split('/')[1];
            } else if (memo.id) {
                memoId = memo.id;
            }
            more.href = memoId ? `${this.apiUrl}/m/${memoId}` : `${this.apiUrl}`;
            more.textContent = '查看原文 ↗';
            more.target = '_blank';

            footer.appendChild(time);
            footer.appendChild(more);
            li.appendChild(footer);
            ul.appendChild(li);
        });

        wrapper.appendChild(ul);
        this.container.innerHTML = '';
        this.container.appendChild(wrapper);

        if (!document.getElementById('memos-widget-style')) {
            const style = document.createElement('style');
            style.id = 'memos-widget-style';
            style.textContent = `
                .memos-widget-wrapper {
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
                }
                .memos-list {
                    list-style: none;
                    padding: 0;
                    margin: 0;
                    display: flex;
                    flex-direction: column;
                    gap: 8px;
                }
                .memos-item {
                    padding: 10px 12px;
                    background: #ffffff;
                    border: 1px solid #e2e8f0;
                    border-radius: 8px;
                    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.02);
                    transition: all 0.2s ease;
                    position: relative;
                    overflow: hidden;
                }
                .memos-item::before {
                    content: "";
                    position: absolute;
                    top: 0;
                    left: 0;
                    width: 3px;
                    height: 100%;
                    background: linear-gradient(180deg, #3b82f6 0%, #60a5fa 100%);
                    opacity: 0;
                    transition: opacity 0.2s ease;
                }
                .memos-item:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.08);
                    border-color: #cbd5e1;
                }
                .memos-item:hover::before {
                    opacity: 1;
                }
                .memos-content {
                    color: #1e293b;
                    font-size: 13px;
                    line-height: 1.5;
                    margin-bottom: 6px;
                    word-wrap: break-word;
                    white-space: pre-wrap;
                }
                .memos-tags {
                    display: flex;
                    flex-wrap: wrap;
                    gap: 4px;
                    margin-bottom: 6px;
                }
                .memos-tag {
                    font-size: 10px;
                    color: #3b82f6;
                    background: #eff6ff;
                    padding: 1px 6px;
                    border-radius: 6px;
                    font-weight: 500;
                }
                .memos-footer {
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    margin-top: 4px;
                    padding-top: 4px;
                    border-top: 1px dashed #f1f5f9;
                }
                .memos-time {
                    color: #94a3b8;
                    font-size: 11px;
                    display: flex;
                    align-items: center;
                }
                .memos-more {
                    color: #3b82f6;
                    text-decoration: none;
                    font-size: 11px;
                    font-weight: 500;
                    display: inline-flex;
                    align-items: center;
                    transition: all 0.2s ease;
                }
                .memos-more:hover {
                    color: #1d4ed8;
                    transform: translateX(2px);
                }
                .memos-error {
                    color: #ef4444;
                    font-size: 12px;
                    padding: 8px 10px;
                    background: #fef2f2;
                    border-radius: 6px;
                    border: 1px solid #fee2e2;
                }
                .memos-empty {
                    color: #94a3b8;
                    font-size: 12px;
                }
                @media (prefers-color-scheme: dark) {
                    .memos-item {
                        background: #1e293b;
                        border-color: #334155;
                    }
                    .memos-item:hover {
                        border-color: #475569;
                        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
                    }
                    .memos-content {
                        color: #f1f5f9;
                    }
                    .memos-footer {
                        border-top-color: #334155;
                    }
                    .memos-tag {
                        background: #1e3a8a;
                        color: #93c5fd;
                    }
                }
            `;
            document.head.appendChild(style);
        }
    }

    renderError(error) {
        if (error && error.message && error.message.includes('401')) {
            this.container.innerHTML = '<p class="memos-error">获取 Memos 动态失败：401 未授权（请在后台设置正确的 Access Token）</p>';
        } else {
            this.container.innerHTML = '<p class="memos-error">获取 Memos 动态失败，请稍后重试</p>';
        }
    }
}
