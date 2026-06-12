判斷使用者訊息屬於下列哪一類，只輸出 JSON：{"category":"chat|task|new_domain|configure_notify|skill","reason":"一句話"}
- chat：一般對話、閒聊、問平台能做什麼、釐清需求。
- skill：**查詢或操作平台/系統本身**——這是「立即可回答/執行」的，優先於 task。包含：
  ‧查詢類（請告訴我/列出/有哪些/查看/看一下）：某領域監控什麼、領域清單與細節、目前設定、日誌內容、MCP 工具、檔案內容、系統狀態、上網查資料。
  ‧操作類：改設定/切模型、停用啟用/整合領域包、重啟 worker、中止任務、執行終端機指令、讀寫/編輯檔案、開啟程式、接入管理 MCP、設定「一律允許」。
  ‧**只要是要「實際讀取檔案 / 查看或分析 log / 跑指令 / docker exec / 看某設定檔（如 nginx.conf）/ 查系統狀態」就一定是 skill（要真的去執行，不是聊天）**——例如「讀取 nginx error log」「查看 /etc/nginx 設定」「跑 df -h」「docker exec 看容器日誌」。
- task：要 AI 「去處理一件需要多步推理的事」並交給某個領域協調者背景執行（資安事件響應、調查入侵、修 bug、隔離主機、處理日誌錯誤並自動修復…）。**只有「請 AI 動手處理某事件/案件」才算 task；單純「告訴我/查一下」是 skill。**
- new_domain：想「新增一個持續性的監控/自動化領域」（描述長期職責，例如「監控 X 並自動 Y」）。
- configure_notify：要設定通知平台，訊息含 Telegram/LINE/Slack/webhook 的 token、chat id 或推播對象。

（訊息可能附帶先前對話脈絡；以最後一則使用者意圖為準。）
/no_think 直接輸出 JSON，不要思考、不要解釋。
使用者訊息：「{{message}}」
