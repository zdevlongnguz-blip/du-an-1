"""
Server Python biến câu tiếng Việt thành vector (embedding) cho BE PHP gọi sang.

Embedding: một dãy 768 con số đại diện cho "ý nghĩa" của câu. Hai câu càng gần nghĩa
thì hai vector càng giống nhau (đo bằng cosine similarity, phần này BE PHP tự tính).

Vì sao cần server riêng: model nặng khoảng 540 MB, nạp vào RAM mất vài giây.
Server nạp model MỘT lần lúc khởi động rồi giữ trong RAM, nên mỗi request chỉ mất vài chục ms.

Chạy: xem README.md trong cùng thư mục.
"""

from fastapi import FastAPI, HTTPException
from pydantic import BaseModel
from pyvi.ViTokenizer import tokenize
from sentence_transformers import SentenceTransformer

# Tên model trên Hugging Face. Lần chạy đầu sẽ tự tải về máy (khoảng 540 MB) rồi lưu cache.
MODEL_NAME = "bkai-foundation-models/vietnamese-bi-encoder"

# Số câu tối đa trong một request, tránh BE gửi quá nhiều làm server chậm hoặc hết RAM.
MAX_TEXTS = 64

# Số ký tự tối đa mỗi câu. Model chỉ đọc 256 token (phần dài hơn bị cắt), nên câu quá dài là vô ích.
MAX_TEXT_LENGTH = 2000

app = FastAPI(title="embed-service")

# Nạp model ngay khi khởi động server (chậm vài giây, chỉ một lần).
model = SentenceTransformer(MODEL_NAME)


class EmbedRequest(BaseModel):
    """Body của POST /embed: danh sách câu cần biến thành vector."""

    texts: list[str]


@app.get("/health")
def health():
    """Cho BE kiểm tra server có đang chạy không."""
    return {
        "status": "ok",
        "model": MODEL_NAME,
        "dim": model.get_sentence_embedding_dimension(),
    }


@app.post("/embed")
def embed(body: EmbedRequest):
    """
    Nhận danh sách câu, trả về danh sách vector theo đúng thứ tự.

    Lỗi 422: danh sách rỗng, quá MAX_TEXTS câu, có câu rỗng hoặc quá dài.
    """
    texts = body.texts
    if len(texts) == 0 or len(texts) > MAX_TEXTS:
        raise HTTPException(status_code=422, detail=f"texts phải có từ 1 đến {MAX_TEXTS} câu")

    for text in texts:
        if text.strip() == "" or len(text) > MAX_TEXT_LENGTH:
            raise HTTPException(
                status_code=422,
                detail=f"Mỗi câu phải khác rỗng và tối đa {MAX_TEXT_LENGTH} ký tự",
            )

    # Model này BẮT BUỘC đầu vào đã tách từ: "Hà Nội là thủ đô" -> "Hà_Nội là thủ_đô".
    # Từ ghép tiếng Việt được nối bằng dấu "_" để model hiểu là một từ. Bỏ bước này kết quả kém hẳn.
    segmented = [tokenize(text.strip()) for text in texts]

    # Không chuẩn hóa (normalize) vector ở đây: BE tự tính cosine nên vector giữ nguyên độ dài gốc.
    vectors = model.encode(segmented, convert_to_numpy=True)

    return {
        "vectors": vectors.tolist(),
        "dim": int(vectors.shape[1]),
    }
