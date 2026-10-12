# 个人贡献报告 —— COMPX574 mini-project

**姓名：** _[填写]_　**学号：** _[填写]_　**邮箱：** _[填写]_
**GitHub：** [@RisonG](https://github.com/RisonG)
**项目：** [mealie-recipes/mealie](https://github.com/mealie-recipes/mealie)

## 摘要

| # | 贡献 | 类型 | 状态 |
|---|---|---|---|
| 1 | 删除仅作为菜谱替代品使用的 food 会留下孤立引用 | Issue [#8306](https://github.com/mealie-recipes/mealie/issues/8306) | 被采纳，当天由维护者修复 |
| 2 | 单个 food 的 description 为 NULL 会让整个 foods 列表接口崩溃 | Issue [#8308](https://github.com/mealie-recipes/mealie/issues/8308) | 被采纳，当天修复 |
| 3 | iOS 相机重复照片覆盖菜谱步骤附件 | PR [#8111](https://github.com/mealie-recipes/mealie/pull/8111) | **已合并** 2026-09-16 |
| 4 | 购物清单拖拽排序后顺序回退 | PR [#8487](https://github.com/mealie-recipes/mealie/pull/8487) | 开启中，等待审阅 |
| 5 | 小组代码评审 —— 证据档案 | 书面交付物 | 2026 年 9 月提交 |
| 6 | Workflow 3：用单个 AI 代理把 Mealie API 重写为 PHP | PR [xiaofang2016/mealie#2](https://github.com/xiaofang2016/mealie/pull/2) | **已合并** |

两份 bug 报告被采纳并由维护者修复，一个修复已合入上游，一个修复等待审阅，一份书面交付物，一次 AI 工作流实验。

## 1. 对上游 Mealie 的贡献

### 1.1 PR #8111 —— iOS 相机重复照片覆盖菜谱步骤附件（已合并）

[pull/8111](https://github.com/mealie-recipes/mealie/pull/8111) · 2026-09-16 合并 · 3 commits，+52/−2，2 个文件

**在实际使用中发现。** 在 iOS 上用应用内相机连拍多张照片附到菜谱步骤，只有第一张被保留，之后每一张的链接都指向同一个文件。

**成因。** iOS Safari 对每一次相机拍摄都命名为 `image.jpg`，无论一次会话拍了多少张 —— 而从相册选取的照片保留各自的文件名，这就是那条路径从未暴露该 bug 的原因。`POST /api/recipes/{slug}/assets` 通过 `slugify()` 从客户端提供的 `name` 推导磁盘文件名，没有唯一性检查，并以 `"wb"` 打开目标文件。于是同名的第二次上传在静默覆盖磁盘上第一个文件的**同时**，还插入了第二条指向同一 URL 的 `RecipeAsset` 记录。

**修复。** 在 `upload_recipe_asset` 中，写入前检查目标文件是否已存在；若存在，追加一段 uuid4 短后缀，使第二个附件获得自己的路径。补充了一个集成测试：以同一名称上传两次，断言两个文件都存在。

涉及文件：`mealie/routes/recipe/recipe_crud_routes.py`（+9/−2）、`tests/integration_tests/user_recipe_tests/test_recipe_image_assets.py`（+43）。

### 1.2 PR #8487 —— 购物清单拖拽排序后回退（开启中）

[pull/8487](https://github.com/mealie-recipes/mealie/pull/8487) · 2026-09-21 创建 · 1 commit，+16/−0，2 个文件 · 修复既有 issue [#5010](https://github.com/mealie-recipes/mealie/issues/5010)（由他人报告）

**现象。** 把购物清单条目拖到新位置，新顺序短暂显示后弹回原状。

**两个成因，都必须修。**

1. `updateUncheckedListItems()` 在把更新入队前没有更新各条目的 `updatedAt`，而 `saveListItem()` 会更新。客户端在把 GET 响应与尚未完成的本地改动做合并时比较时间戳，因此无法判断被重排的条目比服务端副本更新，有时会丢弃本地的重排结果。拖拽排序、全选、全不选三条路径都走这里。
2. 页面每五秒轮询一次服务端。一个较早发出的 GET 若**晚于**较新的 PUT/GET 对返回 —— 常见的网络响应乱序 —— 即使重排已经保存成功，也会用过期数据覆盖界面。现在 `refresh()` 给每次调用打上递增的代号，若在其返回前已有更新的 refresh 开始，则丢弃自己的结果。

涉及文件：`frontend/app/composables/shopping-list-page/sub-composables/use-shopping-list-crud.ts`（+3）、`use-shopping-list-data.ts`（+13）。

**状态：** 自 2026-09-21 开启至今，未收到修改要求。

### 1.3 Issue #8306 与 #8308 —— `ingredient_foods` 的数据完整性

两者都是在 foods 区域工作时发现，均于 2026-09-06 提交，并在**当天**被接手并修复 —— **修复者是他人**。如实陈述：此处的贡献是诊断，以及一份清晰到足以让人直接动手的复现，不是补丁本身。

| Issue | 内容 | 修复者 |
|---|---|---|
| [#8306](https://github.com/mealie-recipes/mealie/issues/8306) | 删除一个仅作为菜谱**替代品**使用的 food，会留下孤立或损坏的引用，且不给用户任何警告 | 维护者 `michael-genson`，PR [#8311](https://github.com/mealie-recipes/mealie/pull/8311) —— "Thanks for the report! This will be fixed by…" |
| [#8308](https://github.com/mealie-recipes/mealie/issues/8308) | 一条 `description` 为 NULL 的 food 记录会让整个 foods 列表接口返回 500；前端静默显示为"无数据"，使成因完全不可见 | 贡献者 `chiliec`，PR [#8309](https://github.com/mealie-recipes/mealie/pull/8309) |

两个修复 PR 均于 2026-09-06 合并。

## 2. 小组代码评审 —— 证据档案

为小组对 Mealie 的代码评审（2026 年 9 月提交），我产出了报告据以撰写的证据档案，全部对照提交 `56fbf6ad0` 核验：

- **七个候选问题**，每个都指明具体文件并给出切实后果，供小组筛选为五个 —— 包括客户端自行发明的同步语义而服务端无并发控制、工具链中没有任何环节对前端做类型检查、生成代码被当作事实来源却无任何机制验证其已重新生成、测试之间没有隔离、service 层只是约定而非强制、51 个迁移却只有一个静态顺序测试，以及维护者本人已标记为不足的 groups/households 权限模型。
- **五项正面设计选择**，供筛选为三项。
- **一份优先级排序**，按影响对成本与维护者精力的权衡，面向一个发布周期。
- **两条常见批评经核查后被证伪**，以免有人写进报告："前端没有测试"（实有 34 个测试文件）、"CI 薄弱"（实有 sqlite + postgres 矩阵跑在真实 Postgres 服务容器上、真实 LDAP 容器、Ruff、mypy、CodeQL、Trivy、Playwright e2e，以及 Conventional-Commits 标题检查）。

文件：`code-review-evidence.md`、`Mealie-Code-Review-Report-DRAFT.docx`。

## 3. Workflow 3 —— 用单个 AI 代理完成 PHP 重写

这是我在小组 AI 工作流对比中负责的部分。另外两个工作流（单条指令、多代理）由队友完成，跨工作流的对比分析在小组报告中。以下只是我自己这一次运行。

**方法。** 一个 Claude Code 代理，不使用子代理，按 `mealie/routes/` 的模块分九轮推进。每轮：先为一个模块写契约 —— 每个接口的方法与完整路径、对应的 Python 源码行、JSON 字段名所依据的 schema 文件、id 如何存储与返回、由哪段 Python 检查把关、以及明确不实现什么 —— 然后停下接受审查，再只实现该契约，最后计分。

**模型、成本与投入** —— 读自 Claude Code 的会话记录，非估算：

| 项目 | 值 |
|---|---|
| 模型 | `claude-opus-5`，全部 843 次助手回合，无降级 |
| 工具调用 | 366 次 |
| **代理活跃工作时长** | **约 2.6 小时** |
| 墙钟 | 48.9 小时，其中 46.3 小时代理处于空闲、等待我回复 |
| 我自己的轮次 | 约 20 次：约 11 次搭环境与设计方法，之后 9 次放行 |
| Token | 输出 962k · 缓存写 10.5M · 缓存读 320M |

不给出金额：金额取决于订阅方案而非这些计数，`/usage` 是权威来源。

**结果。** 共享 parity 检查 265/265，body 准确率 43/43。

**这个分数意味着什么、不意味着什么。** 265 个计分请求中，Python 基准端只对 75 个返回 2xx，其余都是拒绝路径。按方法拆分，144 个请求是写操作，其中恰好只有 2 个走到成功路径 —— 因为该共享检查有意发送会被拒绝的 body 和零 id，以便不动共享数据库。所以这个检查主要衡量的是"路由是否存在、参数校验是否与 FastAPI 一致"，而不是"背后的活有没有干"。具体而言：我的通过项里有 38 个属于 `/api/admin/*`，而我在该包中没有实现任何行为；另有约一半的写路径按设计返回 501。每一条都在契约文件的"不实现什么"下列明。

**如果重来我会改什么。** 契约规定的是接口行为，从未规定内部结构，因此产出是 38 个 controller、100 处直接数据库调用、没有 service 层也没有 repository 层 —— 恰恰是我们自己的代码评审在 Mealie 的 Python 中指出过的那个弱点。契约模板里加一行（"controller 不得直接访问数据库"）很可能就能避免。这是对方法的廉价改进，不是模型的局限。

**交付物**（PR [xiaofang2016/mealie#2](https://github.com/xiaofang2016/mealie/pull/2)，已合并，10 commits，162 个文件 +41,065 行）：

- `php-w3/` —— 重写本体，Laravel 13 + PHP 8.4
- `compx574/workflow-3-contracts.md` —— 九份契约，约 1,500 行
- `compx574/workflow-3/log.md` —— 环境、九条偏离及其理由、逐轮时间线
- `compx574/workflow-3/{measure.sh, accuracy_compare_w3.py, sandbox/, results/}` —— 计分脚手架，派生自多代理工作流的版本以保证数字可比
- `compx574/workflow-3/report-w3.md` —— 我在小组报告中负责的那一节

一条值得记录的过程事项：第 5 轮我自己的自测向共享数据库写入了两行，原因是两个 schema 的每个字段都有默认值，因而接受空对象。跑完后的校验和比对发现了它，数据库已从快照还原；我补充了 `sandbox/restore-db.sh`，以及"探测用的 body 只有在 schema 存在必填字段时才安全"这条规则，其他工作流可以复用。
