CXX = g++
SRCS = library/preprocessor.cpp
OUT = bin/preprocessor


all: $(OUT)

help:
	@echo "Targets:"
	@echo "  all     - build preprocessor"
	@echo "  clean   - clear generated binaries"
	@echo "  help    - show this message\n"

clean:
	rm -f $(OUT)

$(OUT): $(SRCS)
	$(CXX) -o $(OUT) -O2 -s $(SRCS)

.PHONY: all help clean
